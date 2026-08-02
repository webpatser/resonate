<?php

use Webpatser\Resonate\Scaling\Contracts\PubSubIncomingMessageHandler;
use Webpatser\Resonate\Scaling\RedisPubSubProvider;

use function Fledge\Async\delay;

/*
 * Envelope handling used to run inline in the subscriber fiber, so one envelope
 * that suspended (a metrics gather waiting on siblings, a terminate walking a
 * user's connections, a write to a congested peer) stopped the pump and with it
 * every other node's broadcasts, terminate requests and metrics replies.
 *
 * Handling now runs on a bounded queue with a single worker, which keeps the
 * pump free while preserving the order Redis delivered the envelopes in. The
 * resubscribe loop is covered separately in RedisPubSubResubscribeTest.
 */

/**
 * A message handler whose handling can be made to suspend.
 */
final class RecordingMessageHandler implements PubSubIncomingMessageHandler
{
    /** @var list<string> */
    public array $handled = [];

    public float $duration = 0.0;

    public bool $throw = false;

    public function handle(string $payload): void
    {
        if ($this->duration > 0.0) {
            delay($this->duration);
        }

        if ($this->throw) {
            throw new RuntimeException('handler exploded');
        }

        $this->handled[] = $payload;
    }

    public function listen(string $event, callable $callback): void {}

    public function stopListening(string $event): void {}
}

/**
 * A provider that feeds envelopes in without touching Redis.
 */
final class PumpedPubSubProvider extends RedisPubSubProvider
{
    public function receive(string $message): bool
    {
        return $this->enqueue($message);
    }
}

function pumpedProvider(RecordingMessageHandler $handler, int $maxQueued = 100): PumpedPubSubProvider
{
    return new PumpedPubSubProvider($handler, 'resonate', [], $maxQueued);
}

it('does not hold up the pump while an envelope is being handled', function () {
    $handler = new RecordingMessageHandler;
    $handler->duration = 0.01;

    $provider = pumpedProvider($handler);

    // Standing in for the subscription loop: every envelope Redis delivers is
    // accepted immediately, even though the first is still being handled.
    foreach (range(1, 5) as $index) {
        expect($provider->receive("envelope-{$index}"))->toBeTrue();
    }

    expect($handler->handled)->toBe([])
        ->and($provider->envelopes()->size())->toBe(5);

    drainLoop();

    expect($handler->handled)->toBe([
        'envelope-1',
        'envelope-2',
        'envelope-3',
        'envelope-4',
        'envelope-5',
    ]);
});

it('handles envelopes one at a time, in the order they arrived', function () {
    $handler = new RecordingMessageHandler;
    $handler->duration = 0.002;

    $provider = pumpedProvider($handler);

    foreach (range(1, 8) as $index) {
        $provider->receive("envelope-{$index}");
    }

    drainLoop();

    expect($handler->handled)->toBe(array_map(
        fn (int $index) => "envelope-{$index}",
        range(1, 8),
    ));
});

it('drops envelopes past the bound rather than buffering without limit', function () {
    $handler = new RecordingMessageHandler;
    $handler->duration = 0.002;

    $provider = pumpedProvider($handler, maxQueued: 3);

    expect($provider->receive('one'))->toBeTrue()
        ->and($provider->receive('two'))->toBeTrue()
        ->and($provider->receive('three'))->toBeTrue()
        ->and($provider->receive('four'))->toBeFalse();

    drainLoop();

    expect($handler->handled)->toBe(['one', 'two', 'three']);

    // The pump keeps working once the backlog clears.
    expect($provider->receive('five'))->toBeTrue();

    drainLoop();

    expect($handler->handled)->toBe(['one', 'two', 'three', 'five']);
});

it('keeps pumping after an envelope blows up in the handler', function () {
    $handler = new RecordingMessageHandler;
    $handler->throw = true;

    $provider = pumpedProvider($handler);

    $provider->receive('explodes');

    drainLoop();

    $handler->throw = false;

    $provider->receive('survives');

    drainLoop();

    expect($handler->handled)->toBe(['survives']);
});

it('drops queued envelopes when the provider disconnects', function () {
    $handler = new RecordingMessageHandler;
    $handler->duration = 0.01;

    $provider = pumpedProvider($handler);

    $provider->receive('first');
    $provider->receive('second');

    $provider->disconnect();

    drainLoop();

    expect($handler->handled)->toBe([])
        ->and($provider->envelopes())->toBeNull();
});

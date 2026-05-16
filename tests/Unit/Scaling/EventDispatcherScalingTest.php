<?php

use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Protocols\Pusher\EventDispatcher;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;

/**
 * A pub/sub provider that records every published envelope.
 */
class RecordingPubSubProvider implements PubSubProvider
{
    /** @var array<int, array<string, mixed>> */
    public array $published = [];

    public function connect(): void {}

    public function disconnect(): void {}

    public function subscribe(): void {}

    public function on(string $event, callable $callback): void {}

    public function listen(string $event, callable $callback): void {}

    public function stopListening(string $event): void {}

    public function publish(array $payload): void
    {
        $this->published[] = $payload;
    }
}

/**
 * A server provider that publishes events.
 */
class PublishingServerProvider extends ServerProvider
{
    public function shouldPublishEvents(): bool
    {
        return true;
    }
}

/**
 * A server provider that does not publish events.
 */
class LocalOnlyServerProvider extends ServerProvider
{
    //
}

beforeEach(function () {
    $this->pubSub = new RecordingPubSubProvider;
    $this->app->instance(PubSubProvider::class, $this->pubSub);
    $this->app->instance(ServerProvider::class, new PublishingServerProvider);

    $this->application = app(ApplicationProvider::class)->findById('app-id');
});

it('publishes a JSON message envelope when scaling is enabled', function () {
    EventDispatcher::dispatch($this->application, [
        'channel' => 'test-channel',
        'event' => 'App\\Events\\Test',
        'data' => [],
    ]);

    expect($this->pubSub->published)->toHaveCount(1);

    $envelope = $this->pubSub->published[0];

    expect($envelope['type'])->toBe('message');
    expect($envelope['payload'])->toBe([
        'channel' => 'test-channel',
        'event' => 'App\\Events\\Test',
        'data' => [],
    ]);
});

it('carries the application as its id string, not a serialized blob', function () {
    EventDispatcher::dispatch($this->application, [
        'channel' => 'test-channel',
        'event' => 'x',
        'data' => [],
    ]);

    $envelope = $this->pubSub->published[0];

    expect($envelope['application'])->toBe('app-id');
    expect($envelope['application'])->toBeString();

    // Not a PHP serialized object payload.
    expect($envelope['application'])->not->toStartWith('O:');

    // The whole envelope round-trips through JSON cleanly.
    $json = json_encode($envelope, JSON_THROW_ON_ERROR);
    expect(json_decode($json, true))->toBe($envelope);
});

it('does not publish when the server should not publish events', function () {
    $this->app->instance(ServerProvider::class, new LocalOnlyServerProvider);

    EventDispatcher::dispatch($this->application, [
        'channel' => 'test-channel',
        'event' => 'x',
        'data' => [],
    ]);

    expect($this->pubSub->published)->toBeEmpty();
});

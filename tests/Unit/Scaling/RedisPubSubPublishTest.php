<?php

use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Protocol\RedisResponse;
use Fledge\Async\Redis\Protocol\RedisValue;
use Fledge\Async\Redis\RedisClient;
use Webpatser\Resonate\Scaling\Contracts\PubSubIncomingMessageHandler;
use Webpatser\Resonate\Scaling\RedisPubSubProvider;

/*
 * A payload that cannot be encoded (INF, NAN, invalid UTF-8) must fail loudly
 * at publish time rather than reach Redis as `false` cast to an empty string,
 * which every sibling node would then fail to decode (Reverb #421). The
 * receiving side is covered in RedisPubSubPumpTest.
 */

/**
 * A Redis link that records the commands sent over it.
 */
final class RecordingRedisLink implements RedisLink
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $commands = [];

    public function execute(string $command, array $parameters): RedisResponse
    {
        $this->commands[] = [$command, $parameters];

        return new RedisValue(2);
    }
}

/**
 * A provider whose publisher is wired to a given client instead of Redis.
 */
final class WiredPublishProvider extends RedisPubSubProvider
{
    public function usePublisher(RedisClient $publisher): self
    {
        $this->publisher = $publisher;

        return $this;
    }
}

function wiredPublishProvider(RecordingRedisLink $link): WiredPublishProvider
{
    $handler = new class implements PubSubIncomingMessageHandler
    {
        public function handle(string $payload): void {}

        public function listen(string $event, callable $callback): void {}

        public function stopListening(string $event): void {}
    };

    return (new WiredPublishProvider($handler, 'resonate'))->usePublisher(new RedisClient($link));
}

it('publishes an encodable payload as JSON', function () {
    $provider = wiredPublishProvider($link = new RecordingRedisLink);

    expect($provider->publish(['type' => 'message']))->toBe(2)
        ->and($link->commands)->toBe([['publish', ['resonate', '{"type":"message"}']]]);
});

it('throws instead of publishing a payload that cannot be encoded', function (mixed $value) {
    $provider = wiredPublishProvider($link = new RecordingRedisLink);

    expect(fn () => $provider->publish(['type' => 'message', 'payload' => $value]))
        ->toThrow(JsonException::class);

    expect($link->commands)->toBeEmpty();
})->with([
    'infinity' => [INF],
    'not a number' => [NAN],
    'invalid utf-8' => ["\xB1\x31"],
]);

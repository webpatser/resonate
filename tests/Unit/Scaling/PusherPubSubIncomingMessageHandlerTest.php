<?php

use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Scaling\PusherPubSubIncomingMessageHandler;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

beforeEach(function () {
    $this->handler = new PusherPubSubIncomingMessageHandler;
    $this->application = app(ApplicationProvider::class)->findById('app-id');
});

it('routes a JSON message envelope to subscribed connections', function () {
    $connection = new FakeConnection;

    channels($this->application)->findOrCreate('test-channel')->subscribe($connection);

    $payload = json_encode([
        'type' => 'message',
        'application' => 'app-id',
        'payload' => [
            'channel' => 'test-channel',
            'event' => 'App\\Events\\Test',
            'data' => ['message' => 'hello'],
        ],
    ]);

    $this->handler->handle($payload);

    $connection->assertReceived([
        'channel' => 'test-channel',
        'event' => 'App\\Events\\Test',
        'data' => ['message' => 'hello'],
    ]);
});

it('excludes the originating socket id from a message envelope', function () {
    $sender = new FakeConnection;
    $receiver = new FakeConnection;

    $channel = channels($this->application)->findOrCreate('test-channel');
    $channel->subscribe($sender);
    $channel->subscribe($receiver);

    $payload = json_encode([
        'type' => 'message',
        'application' => 'app-id',
        'socket_id' => $sender->id(),
        'payload' => [
            'channel' => 'test-channel',
            'event' => 'App\\Events\\Test',
            'data' => [],
        ],
    ]);

    $this->handler->handle($payload);

    $sender->assertNothingReceived();
    $receiver->assertReceivedCount(1);
});

it('disconnects the matching user on a terminate envelope', function () {
    $matching = new FakeConnection;
    $other = new FakeConnection;

    $channel = channels($this->application)->findOrCreate('test-channel');
    $channel->subscribe($matching, data: json_encode(['user_id' => 42]));
    $channel->subscribe($other, data: json_encode(['user_id' => 99]));

    $payload = json_encode([
        'type' => 'terminate',
        'application' => 'app-id',
        'payload' => ['user_id' => '42'],
    ]);

    $this->handler->handle($payload);

    expect($matching->wasTerminated)->toBeTrue();
    expect($other->wasTerminated)->toBeFalse();
});

it('decodes envelopes as pure JSON without unserialize', function () {
    // A serialized PHP object in the application field must never be revived.
    // The handler resolves the application from its plain id string, so a
    // serialized blob simply fails to resolve as an application id.
    $serialized = serialize(app(ApplicationProvider::class)->findById('app-id'));

    $payload = json_encode([
        'type' => 'message',
        'application' => $serialized,
        'payload' => ['channel' => 'test-channel', 'event' => 'x', 'data' => []],
    ]);

    expect(fn () => $this->handler->handle($payload))
        ->toThrow(Webpatser\Resonate\Exceptions\InvalidApplication::class);

    // Sanity check: the envelope itself is valid JSON (no PHP serialization
    // format on the wire for the envelope structure).
    expect(json_decode($payload, true))->toBeArray()
        ->toHaveKeys(['type', 'application', 'payload']);
});

it('invokes registered event listeners for the matching type', function () {
    $seen = [];

    $this->handler->listen('message', function ($event) use (&$seen) {
        $seen[] = $event['type'];
    });

    channels($this->application)->findOrCreate('test-channel');

    $this->handler->handle(json_encode([
        'type' => 'message',
        'application' => 'app-id',
        'payload' => ['channel' => 'test-channel', 'event' => 'x', 'data' => []],
    ]));

    expect($seen)->toBe(['message']);

    $this->handler->stopListening('message');

    $this->handler->handle(json_encode([
        'type' => 'message',
        'application' => 'app-id',
        'payload' => ['channel' => 'test-channel', 'event' => 'x', 'data' => []],
    ]));

    expect($seen)->toBe(['message']);
});

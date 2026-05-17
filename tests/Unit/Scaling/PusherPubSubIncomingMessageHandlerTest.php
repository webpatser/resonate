<?php

use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Scaling\PusherPubSubIncomingMessageHandler;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

/*
 * Pulse PII assessment note (Track D):
 *
 * `src/Pulse/Recorders/ResonateConnections.php` records only an integer
 * connection count per app (`->get('/connections')->connections`) on a
 * 15-second beat: no frame body, no auth token, no presence channel_data.
 *
 * `src/Pulse/Recorders/ResonateMessages.php` records only an aggregated
 * `->count()` keyed by app id with the type set to `reverb_message:sent`
 * or `reverb_message:received`. The raw message string carried by the
 * `MessageSent`/`MessageReceived` events is never persisted.
 *
 * Neither recorder ingests raw frame bodies, so the Sanitizer does not need
 * to be applied to the Pulse path. No PII-leak test is required.
 */

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
    // serialized blob simply fails to resolve as an application id. The
    // resulting InvalidApplication exception is caught by handle()'s
    // robustness wrapper so the receive loop survives.
    $serialized = serialize(app(ApplicationProvider::class)->findById('app-id'));

    $connection = new FakeConnection;
    channels($this->application)->findOrCreate('test-channel')->subscribe($connection);

    $payload = json_encode([
        'type' => 'message',
        'application' => $serialized,
        'payload' => ['channel' => 'test-channel', 'event' => 'x', 'data' => []],
    ]);

    // No exception escapes; nothing is broadcast because the application
    // never resolves.
    $this->handler->handle($payload);

    $connection->assertNothingReceived();

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

/*
 * Adversarial envelope handling (audit Info finding).
 *
 * `handle()` is the receive-side hot loop. It must drop malformed envelopes
 * without throwing, so a single bad publisher cannot wedge the subscriber
 * fiber or the per-fiber receive loop.
 */

it('drops an envelope with no application field', function () {
    $connection = new FakeConnection;
    channels($this->application)->findOrCreate('test-channel')->subscribe($connection);

    $seen = [];
    $this->handler->listen('message', function ($event) use (&$seen) {
        $seen[] = $event['type'] ?? null;
    });

    $payload = json_encode([
        'type' => 'message',
        'payload' => [
            'channel' => 'test-channel',
            'event' => 'App\\Events\\Test',
            'data' => ['message' => 'hello'],
        ],
    ]);

    // The listener fires from processEventListeners() before the
    // application lookup throws, but no broadcast must reach subscribers
    // and no exception must escape.
    $this->handler->handle($payload);

    $connection->assertNothingReceived();
    expect($seen)->toBe(['message']);
});

it('drops an envelope with an unknown app id', function () {
    $connection = new FakeConnection;
    channels($this->application)->findOrCreate('test-channel')->subscribe($connection);

    $payload = json_encode([
        'type' => 'message',
        'application' => 'nonexistent',
        'payload' => [
            'channel' => 'test-channel',
            'event' => 'App\\Events\\Test',
            'data' => ['message' => 'hello'],
        ],
    ]);

    $this->handler->handle($payload);

    $connection->assertNothingReceived();
});

it('ignores an envelope with no type field', function () {
    $connection = new FakeConnection;
    channels($this->application)->findOrCreate('test-channel')->subscribe($connection);

    $payload = json_encode([
        'application' => 'app-id',
        'payload' => [
            'channel' => 'test-channel',
            'event' => 'App\\Events\\Test',
            'data' => ['message' => 'hello'],
        ],
    ]);

    // The match() falls through to the default => null branch, so the
    // dispatcher is never invoked.
    $this->handler->handle($payload);

    $connection->assertNothingReceived();
});

it('drops a malformed JSON envelope', function () {
    $connection = new FakeConnection;
    channels($this->application)->findOrCreate('test-channel')->subscribe($connection);

    // `json_decode` with JSON_THROW_ON_ERROR raises JsonException; the
    // robustness wrapper catches it.
    $this->handler->handle('not json');

    $connection->assertNothingReceived();
});

it('drops a JSON envelope that is not an array at the top level', function () {
    $connection = new FakeConnection;
    channels($this->application)->findOrCreate('test-channel')->subscribe($connection);

    // A bare JSON number, string, or boolean parses without raising but
    // breaks every `$event['...']` subscript downstream.
    $this->handler->handle('42');
    $this->handler->handle('"hello"');
    $this->handler->handle('null');

    $connection->assertNothingReceived();
});

it('handles an oversized payload without crashing', function () {
    $connection = new FakeConnection;
    channels($this->application)->findOrCreate('other-channel')->subscribe($connection);

    // 1 MB synthetic data field. The handler must process it (no listener
    // fires for a channel with no matching subscribers on this connection)
    // without throwing or hanging.
    $oversized = str_repeat('A', 1024 * 1024);

    $payload = json_encode([
        'type' => 'message',
        'application' => 'app-id',
        'payload' => [
            'channel' => 'unsubscribed-channel',
            'event' => 'App\\Events\\Test',
            'data' => ['blob' => $oversized],
        ],
    ]);

    $this->handler->handle($payload);

    // The receiver was subscribed to a different channel, so nothing
    // arrives, but no exception or OOM should occur either.
    $connection->assertNothingReceived();
});

<?php

use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Plugins\PluginManager;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\EventHandler as PusherEventHandler;
use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\Fakes\FakePubSubBus;
use Webpatser\Resonate\Tests\Fakes\ScaledServerProvider;

beforeEach(function () {
    $this->connection = new FakeConnection;
    $this->pusher = new PusherEventHandler(app(ChannelManager::class), app(PluginManager::class));
});

it('can send an acknowledgement', function () {
    $this->pusher->handle(
        $this->connection,
        'pusher:connection_established'
    );

    $this->connection->assertReceived([
        'event' => 'pusher:connection_established',
        'data' => json_encode([
            'socket_id' => $this->connection->id(),
            'activity_timeout' => 30,
        ]),
    ]);
});

it('can subscribe to a channel', function () {
    $this->pusher->handle(
        $this->connection,
        'pusher:subscribe',
        ['channel' => 'test-channel']
    );

    $this->connection->assertReceived([
        'event' => 'pusher_internal:subscription_succeeded',
        'data' => '{}',
        'channel' => 'test-channel',
    ]);
});

it('can subscribe to an empty channel', function () {
    $this->pusher->handle(
        $this->connection,
        'pusher:subscribe',
        ['channel' => '']
    );

    $this->connection->assertReceived([
        'event' => 'pusher_internal:subscription_succeeded',
        'data' => '{}',
    ]);
});

it('can unsubscribe from a channel', function () {
    $this->pusher->handle(
        $this->connection,
        'pusher:unsubscribe',
        ['channel' => 'test-channel']
    );

    $this->connection->assertNothingReceived();
});

it('can respond to a ping', function () {
    $this->pusher->handle(
        $this->connection,
        'pusher:ping',
    );

    $this->connection->assertReceived([
        'event' => 'pusher:pong',
    ]);
});

it('can correctly format a payload', function () {
    $payload = $this->pusher->formatPayload(
        'foo',
        ['bar' => 'baz'],
        'test-channel',
    );

    expect($payload)->toBe(json_encode([
        'event' => 'pusher:foo',
        'data' => json_encode(['bar' => 'baz']),
        'channel' => 'test-channel',
    ]));

    $payload = $this->pusher->formatPayload('foo');

    expect($payload)->toBe(json_encode([
        'event' => 'pusher:foo',
    ]));
});

it('can correctly format an internal payload', function () {
    $payload = $this->pusher->formatInternalPayload(
        'foo',
        ['bar' => 'baz'],
        'test-channel',
    );

    expect($payload)->toBe(json_encode([
        'event' => 'pusher_internal:foo',
        'data' => json_encode(['bar' => 'baz']),
        'channel' => 'test-channel',
    ]));

    $payload = $this->pusher->formatInternalPayload('foo');

    expect($payload)->toBe(json_encode([
        'event' => 'pusher_internal:foo',
        'data' => '{}',
    ]));
});

it('falls back to local presence data when the scaled gather fails', function () {
    $this->app->instance(PubSubProvider::class, new FakePubSubBus);
    $this->app->instance(ServerProvider::class, new ScaledServerProvider);

    $metrics = Mockery::mock(MetricsHandler::class);
    $metrics->shouldReceive('gather')->andThrow(new RuntimeException('Pub/sub unavailable.'));
    $this->app->instance(MetricsHandler::class, $metrics);

    $data = json_encode(['user_id' => 1, 'user_info' => ['name' => 'Joe']]);

    $this->pusher->handle($this->connection, 'pusher:subscribe', [
        'channel' => 'presence-test-channel',
        'auth' => validAuth($this->connection->id(), 'presence-test-channel', $data),
        'channel_data' => $data,
    ]);

    $this->connection->assertReceived([
        'event' => 'pusher_internal:subscription_succeeded',
        'data' => json_encode(['presence' => ['count' => 1, 'ids' => [1], 'hash' => [1 => ['name' => 'Joe']]]]),
        'channel' => 'presence-test-channel',
    ]);
});

it('includes the members of sibling nodes when subscribing to a scaled presence channel', function () {
    $bus = new FakePubSubBus;
    $this->app->instance(PubSubProvider::class, $bus);
    $this->app->instance(ServerProvider::class, new ScaledServerProvider);

    $application = $this->connection->app();
    $metrics = new MetricsHandler(app(ChannelManager::class), 5.0);
    $this->app->instance(MetricsHandler::class, $metrics);

    // This node hears its own requests; the sibling answers presence_data.
    $bus->subscribers[] = fn (array $envelope) => $metrics->publish([
        'application' => $application,
        'payload' => $envelope['payload'],
    ]);

    $bus->receivers = 2;
    $bus->subscribers[] = function (array $envelope) use ($bus) {
        if (($envelope['payload']['type'] ?? null) === null) {
            return;
        }

        $bus->publish([
            'type' => 'metrics',
            'application' => 'app-id',
            'payload' => [
                'key' => $envelope['payload']['key'],
                'node' => 'sibling-node',
                'metrics' => $envelope['payload']['type'] === 'presence_data'
                    ? ['presence' => ['count' => 1, 'ids' => [2], 'hash' => [2 => ['name' => 'Jane']]]]
                    : [],
            ],
        ]);
    };

    $data = json_encode(['user_id' => 1, 'user_info' => ['name' => 'Joe']]);

    $this->pusher->handle($this->connection, 'pusher:subscribe', [
        'channel' => 'presence-test-channel',
        'auth' => validAuth($this->connection->id(), 'presence-test-channel', $data),
        'channel_data' => $data,
    ]);

    $this->connection->assertReceived([
        'event' => 'pusher_internal:subscription_succeeded',
        'data' => json_encode(['presence' => [
            'count' => 2,
            'ids' => [2, 1],
            'hash' => [2 => ['name' => 'Jane'], 1 => ['name' => 'Joe']],
        ]]),
        'channel' => 'presence-test-channel',
    ]);
});

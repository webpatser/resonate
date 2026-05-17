<?php

use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\Server;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

beforeEach(function () {
    $this->server = $this->app->make(Server::class);
});

it('can handle a connection', function () {
    $this->server->open($connection = new FakeConnection);

    expect($connection->lastSeenAt())->not->toBeNull();

    $connection->assertReceived([
        'event' => 'pusher:connection_established',
        'data' => json_encode([
            'socket_id' => $connection->id(),
            'activity_timeout' => 30,
        ]),
    ]);
});

it('can handle a disconnection', function () {
    $channelManager = Mockery::spy(ChannelManager::class);
    $channelManager->shouldReceive('for')
        ->andReturn($channelManager);
    $this->app->singleton(ChannelManager::class, fn () => $channelManager);
    $server = $this->app->make(Server::class);

    $server->close(new FakeConnection);

    $channelManager->shouldHaveReceived('unsubscribeFromAll');
});

it('can handle a new message', function () {
    $this->server->open($connection = new FakeConnection);
    $this->server->message(
        $connection,
        json_encode([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => 'test-channel',
                'auth' => '123',
            ],
        ]));

    $connection->assertReceived([
        'event' => 'pusher:connection_established',
        'data' => json_encode([
            'socket_id' => $connection->id(),
            'activity_timeout' => 30,
        ]),
    ]);

    $connection->assertReceived([
        'event' => 'pusher_internal:subscription_succeeded',
        'data' => '{}',
        'channel' => 'test-channel',
    ]);
});

it('sends an error if something fails', function () {
    $this->server->message(
        $connection = new FakeConnection,
        'Hi'
    );

    $connection->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4200,
            'message' => 'Invalid message format',
        ]),
    ]);
});

it('can subscribe a user to a channel', function () {
    $this->server->message(
        $connection = new FakeConnection,
        json_encode([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => 'test-channel',
                'auth' => '',
            ],
        ]));

    expect($connection->lastSeenAt())->not->toBeNull();

    $connection->assertReceived([
        'event' => 'pusher_internal:subscription_succeeded',
        'data' => '{}',
        'channel' => 'test-channel',
    ]);
});

it('unsubscribes a user from a channel on disconnection', function () {
    $channelManager = Mockery::spy(ChannelManager::class);
    $channelManager->shouldReceive('for')
        ->andReturn($channelManager);
    $this->app->singleton(ChannelManager::class, fn () => $channelManager);
    $server = $this->app->make(Server::class);

    $server->message(
        $connection = new FakeConnection,
        json_encode([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => 'test-channel',
                'auth' => '',
            ],
        ]));

    $server->close($connection);

    $channelManager->shouldHaveReceived('unsubscribeFromAll')
        ->once()
        ->with($connection);
});

it('counts open connections, not just subscribed ones, against max_connections', function () {
    $this->app['config']->set('reverb.apps.apps.0.max_connections', 1);

    // First connection opens but never subscribes to any channel. Under the old
    // (Reverb-parity) behaviour this would not count against `max_connections`;
    // under the corrected behaviour it does, because the limit is per open WS
    // connection, not per channel membership.
    $this->server->open($connection = new FakeConnection);

    $this->server->open($connectionTwo = new FakeConnection);

    $connectionTwo->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4004,
            'message' => 'Application is over connection quota',
        ]),
    ]);
});

it('decrements the open-connection count when a connection closes', function () {
    $this->app['config']->set('reverb.apps.apps.0.max_connections', 1);

    $this->server->open($connection = new FakeConnection);
    $this->server->close($connection);

    // Slot must free up; the next open should succeed (no pusher:error frame).
    $this->server->open($connectionTwo = new FakeConnection);

    $connectionTwo->assertReceived([
        'event' => 'pusher:connection_established',
        'data' => json_encode([
            'socket_id' => $connectionTwo->id(),
            'activity_timeout' => 30,
        ]),
    ]);
});

it('sends an error if something fails for event type', function () {
    $this->server->message(
        $connection = new FakeConnection,
        json_encode([
            'event' => [],
        ])
    );

    $connection->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4200,
            'message' => 'Invalid message format',
        ]),
    ]);
});

it('sends an error if something fails for data type', function () {
    $this->server->message(
        $connection = new FakeConnection,
        json_encode([
            'event' => 'pusher:subscribe',
            'data' => 'sfsfsfs',
        ])
    );

    $connection->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4200,
            'message' => 'Invalid message format',
        ]),
    ]);
});

it('sends an error if something fails for data channel type', function () {
    $this->server->message(
        $connection = new FakeConnection,
        json_encode([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => [],
            ],
        ])
    );

    $connection->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4200,
            'message' => 'Invalid message format',
        ]),
    ]);

    $this->server->message(
        $connection = new FakeConnection,
        json_encode([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => null,
            ],
        ])
    );

    $connection->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4200,
            'message' => 'Invalid message format',
        ]),
    ]);
});

it('does not log a rate-limited message body', function () {
    $this->app['config']->set('reverb.apps.apps.0.rate_limiting', [
        'enabled' => true,
        'max_attempts' => 1,
        'decay_seconds' => 60,
        'terminate_on_limit' => false,
    ]);

    // Swap a recording Logger in via reflection. The package's `Log` proxy
    // statically caches its resolved Logger, so rebinding the container alone
    // isn't enough once any earlier test has fired a `Log::*` call.
    $recorder = new class implements \Webpatser\Resonate\Contracts\Logger
    {
        public array $bodies = [];

        public function info(string $title, ?string $message = null): void {}

        public function error(string $message): void {}

        public function message(string $message): void
        {
            $this->bodies[] = $message;
        }

        public function line(int $lines = 1): void {}
    };

    $proxy = new ReflectionClass(\Webpatser\Resonate\Loggers\Log::class);
    $previous = $proxy->getStaticPropertyValue('logger');
    $proxy->setStaticPropertyValue('logger', $recorder);

    try {
        $connection = new FakeConnection;

        $accepted = json_encode([
            'event' => 'pusher:subscribe',
            'data' => ['channel' => 'public-one'],
        ]);

        $rejected = json_encode([
            'event' => 'pusher:subscribe',
            'data' => ['channel' => 'public-two'],
        ]);

        $this->server->message($connection, $accepted);
        $this->server->message($connection, $rejected);

        // The accepted message body is logged; the rate-limited one is not.
        expect($recorder->bodies)->toContain($accepted)
            ->and($recorder->bodies)->not->toContain($rejected);
    } finally {
        $proxy->setStaticPropertyValue('logger', $previous);
    }
});

it('allows unlimited messages when no rate limit is configured', function () {
    $this->server->open($connection = new FakeConnection);

    for ($i = 0; $i < 10; $i++) {
        $this->server->message(
            $connection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => ['channel' => 'test-channel-'.$i],
            ])
        );
    }

    $connection->assertReceivedCount(11);
});

it('sends an error if something fails for channel type', function () {
    $this->server->message(
        $connection = new FakeConnection,
        json_encode([
            'event' => 'client-start-typing',
            'channel' => [],
        ])
    );

    $connection->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4200,
            'message' => 'Invalid message format',
        ]),
    ]);
});

it('rejects a message that exceeds max_message_size', function () {
    $this->app['config']->set('reverb.apps.apps.0.max_message_size', 100);
    $this->server->open($connection = new FakeConnection);

    $oversized = json_encode([
        'event' => 'pusher:subscribe',
        'data' => ['channel' => str_repeat('a', 200)],
    ]);

    $this->server->message($connection, $oversized);

    $connection->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4019,
            'message' => 'Message size exceeded',
        ]),
    ]);
});

it('allows a message at the max_message_size limit', function () {
    $this->app['config']->set('reverb.apps.apps.0.max_message_size', 200);
    $this->server->open($connection = new FakeConnection);

    $payload = json_encode([
        'event' => 'pusher:subscribe',
        'data' => ['channel' => 'fits'],
    ]);

    expect(strlen($payload))->toBeLessThanOrEqual(200);

    $this->server->message($connection, $payload);

    $connection->assertReceived([
        'event' => 'pusher_internal:subscription_succeeded',
        'data' => '{}',
        'channel' => 'fits',
    ]);
});

it('treats max_message_size <= 0 as unlimited', function () {
    $this->app['config']->set('reverb.apps.apps.0.max_message_size', 0);
    $this->server->open($connection = new FakeConnection);

    $payload = json_encode([
        'event' => 'pusher:subscribe',
        'data' => ['channel' => str_repeat('a', 50_000)],
    ]);

    $this->server->message($connection, $payload);

    $connection->assertReceived([
        'event' => 'pusher_internal:subscription_succeeded',
        'data' => '{}',
        'channel' => str_repeat('a', 50_000),
    ]);
});

it('allow receiving client event with empty data', function () {
    // Channel and app must exist for the server to process the message
    $channel = channels()->findOrCreate('private-chat.1');

    $connection = collect(factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => 1]))->first();
    $channel->subscribe($connection->connection(),
        validAuth($connection->id(), 'private-chat.1', $data = json_encode($connection->data())), $data);

    $this->server->message(
        $connection->connection(),
        json_encode([
            'event' => 'client-start-typing',
            'channel' => 'private-chat.1',
        ])
    );

    $connection->connection()->assertNothingReceived();
});

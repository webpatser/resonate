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

it('it rejects a connection when the app is over the connection limit', function () {
    $this->app['config']->set('reverb.apps.apps.0.max_connections', 1);
    $this->server->open($connection = new FakeConnection);
    $this->server->message(
        $connection,
        json_encode([
            'event' => 'pusher:subscribe',
            'data' => [
                'channel' => 'my-channel',
            ],
        ])
    );
    $this->server->open($connectionTwo = new FakeConnection);

    $connectionTwo->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4004,
            'message' => 'Application is over connection quota',
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

<?php

use Webpatser\Resonate\Protocols\Pusher\Channels\ChannelConnection;
use Webpatser\Resonate\Protocols\Pusher\ClientEvent;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

beforeEach(function () {
    $this->channelConnectionManager = Mockery::spy(ChannelConnectionManager::class);
    $this->channelConnectionManager->shouldReceive('for')
        ->andReturn($this->channelConnectionManager);

    $this->app->instance(ChannelConnectionManager::class, $this->channelConnectionManager);
});

it('can forward a client message', function () {
    channels()->findOrCreate('private-test-channel');

    $connectionOne = collect(factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => '1']))->first();
    $connectionTwo = collect(factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => '2']))->first();

    $this->channelConnectionManager->shouldReceive('find')
        ->andReturn($connectionOne);
    $this->channelConnectionManager->shouldReceive('all')
        ->andReturn([$connectionOne, $connectionTwo]);

    ClientEvent::handle(
        $connectionOne->connection(), [
            'event' => 'client-test-message',
            'channel' => 'private-test-channel',
            'data' => ['foo' => 'bar'],
        ]
    );

    $connectionOne->connection()->assertNothingReceived();
    $connectionTwo->connection()->assertReceived([
        'event' => 'client-test-message',
        'channel' => 'private-test-channel',
        'data' => ['foo' => 'bar'],
        'user_id' => '1',
    ]);
});

it('rejects a client event on a public channel', function () {
    channels()->findOrCreate('test-channel');

    $connections = factory(3);

    ClientEvent::handle(
        $connections[0]->connection(), [
            'event' => 'client-test-message',
            'channel' => 'test-channel',
            'data' => ['foo' => 'bar'],
        ]
    );

    $connections[0]->connection()->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4009,
            'message' => 'Client events are only allowed on private and presence channels.',
        ]),
    ]);

    $connections[1]->connection()->assertNothingReceived();
    $connections[2]->connection()->assertNothingReceived();
});

it('does not forward unauthenticated client message when in members mode', function () {
    channels()->findOrCreate('private-test-channel');

    $connectionOne = collect(factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => '1']))->first();
    $connectionTwo = collect(factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => '2']))->first();

    $this->channelConnectionManager->shouldReceive('find')
        ->andReturn(null);
    $this->channelConnectionManager->shouldReceive('all')
        ->andReturn([$connectionTwo]);

    ClientEvent::handle(
        $connectionOne->connection(), [
            'event' => 'client-test-message',
            'channel' => 'private-test-channel',
            'data' => ['foo' => 'bar'],
        ]
    );

    $connectionOne->connection()->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4009,
            'message' => 'The client is not a member of the specified channel.',
        ]),
    ]);
    $connectionTwo->connection()->assertNothingReceived();
});

it('does not forward client message when set to none', function () {
    $this->app['config']->set('reverb.apps.apps.0.accept_client_events_from', 'none');
    channels()->findOrCreate('private-test-channel');

    $connectionOne = collect(factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => '1']))->first();
    $connectionTwo = collect(factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => '2']))->first();

    $this->channelConnectionManager->shouldReceive('find')
        ->andReturn($connectionOne);
    $this->channelConnectionManager->shouldReceive('all')
        ->andReturn([$connectionOne, $connectionTwo]);

    ClientEvent::handle(
        $connectionOne->connection(), [
            'event' => 'client-test-message',
            'channel' => 'private-test-channel',
            'data' => ['foo' => 'bar'],
        ]
    );

    $connectionOne->connection()->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4301,
            'message' => 'The app does not have client messaging enabled.',
        ]),
    ]);
    $connectionTwo->connection()->assertNothingReceived();
});

it('rejects a non-subscribed sender even when set to all', function () {
    // Pusher protocol requires the sender to be a member of the channel. Resonate's
    // 'all' mode used to skip this check (audit finding #5). The fix applies the
    // subscription check in both modes; only the membership-claim source differs.
    $this->app['config']->set('reverb.apps.apps.0.accept_client_events_from', 'all');
    channels()->findOrCreate('private-test-channel');

    $sender = new FakeConnection;
    $other = collect(factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => '1']))->first();

    $this->channelConnectionManager->shouldReceive('find')
        ->andReturn(null);
    $this->channelConnectionManager->shouldReceive('all')
        ->andReturn([$other]);

    ClientEvent::handle(
        $sender, [
            'event' => 'client-test-message',
            'channel' => 'private-test-channel',
            'data' => ['foo' => 'bar'],
        ]
    );

    $sender->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4009,
            'message' => 'The client is not a member of the specified channel.',
        ]),
    ]);
    $other->connection()->assertNothingReceived();
});

it('rejects a client event on a public channel even in all mode', function () {
    $this->app['config']->set('reverb.apps.apps.0.accept_client_events_from', 'all');
    channels()->findOrCreate('test-channel');

    $sender = new FakeConnection;

    ClientEvent::handle(
        $sender, [
            'event' => 'client-test-message',
            'channel' => 'test-channel',
            'data' => ['foo' => 'bar'],
        ]
    );

    $sender->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4009,
            'message' => 'Client events are only allowed on private and presence channels.',
        ]),
    ]);
});

it('overrides a sender-supplied user_id with the authenticated value', function () {
    // A subscribed presence-channel member sends a client event with a spoofed
    // `user_id` in the data payload. The server must echo the authenticated
    // user_id (sourced from the channel-auth signed channel_data), never the
    // sender-supplied one.
    $this->app['config']->set('reverb.apps.apps.0.accept_client_events_from', 'all');
    channels()->findOrCreate('presence-test-channel');

    $sender = collect(factory(data: ['user_info' => ['name' => 'Real'], 'user_id' => 'real-user']))->first();
    $other = collect(factory(data: ['user_info' => ['name' => 'Other'], 'user_id' => 'other-user']))->first();

    $this->channelConnectionManager->shouldReceive('find')
        ->andReturn($sender);
    $this->channelConnectionManager->shouldReceive('all')
        ->andReturn([$sender, $other]);

    ClientEvent::handle(
        $sender->connection(), [
            'event' => 'client-test-message',
            'channel' => 'presence-test-channel',
            'data' => ['user_id' => 'spoofed-user', 'payload' => 'x'],
        ]
    );

    $other->connection()->assertReceived([
        'event' => 'client-test-message',
        'channel' => 'presence-test-channel',
        'data' => ['user_id' => 'spoofed-user', 'payload' => 'x'],
        'user_id' => 'real-user',
    ]);
});

it('does not forward a message to itself', function () {
    $connection = new ChannelConnection(new FakeConnection);
    channels()->findOrCreate('private-test-channel');

    $this->channelConnectionManager->shouldReceive('all')
        ->once()
        ->andReturn([$connection]);
    $this->channelConnectionManager->shouldReceive('find')
        ->andReturn($connection);

    ClientEvent::handle(
        $connection->connection(), [
            'event' => 'client-test-message',
            'channel' => 'private-test-channel',
            'data' => ['foo' => 'bar'],
        ]
    );

    $connection->connection()->assertNothingReceived();
});

it('fails on unsupported message', function () {
    channels()->findOrCreate('test-channel');

    $connection = new FakeConnection;

    $this->channelConnectionManager->shouldNotReceive('hydratedConnections');

    ClientEvent::handle(
        $connection, [
            'event' => 'test-message',
            'channel' => 'test-channel',
            'data' => ['foo' => 'bar'],
        ]
    );
});

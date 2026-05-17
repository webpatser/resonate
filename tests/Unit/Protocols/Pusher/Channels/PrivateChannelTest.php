<?php

use Webpatser\Resonate\Protocols\Pusher\Channels\PresenceChannel;
use Webpatser\Resonate\Protocols\Pusher\Channels\PrivateChannel;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Webpatser\Resonate\Protocols\Pusher\Exceptions\ConnectionUnauthorized;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

beforeEach(function () {
    $this->connection = new FakeConnection;
    $this->channelConnectionManager = Mockery::spy(ChannelConnectionManager::class);
    $this->channelConnectionManager->shouldReceive('for')
        ->andReturn($this->channelConnectionManager);
    $this->app->instance(ChannelConnectionManager::class, $this->channelConnectionManager);
});

it('can subscribe a connection to a channel', function () {
    $channel = new PrivateChannel('private-test-channel');

    $this->channelConnectionManager->shouldReceive('add')
        ->once()
        ->with($this->connection, []);

    $channel->subscribe($this->connection, validAuth($this->connection->id(), 'private-test-channel'));
});

it('can unsubscribe a connection from a channel', function () {
    $channel = new PrivateChannel('private-test-channel');

    $this->channelConnectionManager->shouldReceive('remove')
        ->once()
        ->with($this->connection);

    $channel->unsubscribe($this->connection);
});

it('can broadcast to all connections of a channel', function () {
    $channel = new PrivateChannel('test-channel');

    $this->channelConnectionManager->shouldReceive('add');

    $this->channelConnectionManager->shouldReceive('all')
        ->once()
        ->andReturn($connections = factory(3));

    $channel->broadcast(['foo' => 'bar']);

    collect($connections)->each(fn ($connection) => $connection->assertReceived(['foo' => 'bar']));
});

it('fails to subscribe if the signature is invalid', function () {
    $channel = new PrivateChannel('private-test-channel');

    $this->channelConnectionManager->shouldNotReceive('subscribe');

    $channel->subscribe($this->connection, 'invalid-signature');
})->throws(ConnectionUnauthorized::class);

it('fails to subscribe to a private channel with no auth token', function () {
    $channel = new PrivateChannel('private-test-channel');

    $channel->subscribe($this->connection, null);
})->throws(ConnectionUnauthorized::class);

it('fails to subscribe to a presence channel with no auth token', function () {
    $channel = new PresenceChannel('presence-test-channel');

    $channel->subscribe($this->connection, null);
})->throws(ConnectionUnauthorized::class);

it('rejects a subscribe with the wrong app secret', function () {
    $channel = new PrivateChannel('private-test-channel');

    $signature = "{$this->connection->id()}:private-test-channel";
    $auth = 'app-key:'.hash_hmac('sha256', $signature, 'wrong-secret');

    $channel->subscribe($this->connection, $auth);
})->throws(ConnectionUnauthorized::class);

it('rejects a subscribe with no auth token to a private channel', function () {
    $channel = new PrivateChannel('private-test-channel');

    $channel->subscribe($this->connection, null);
})->throws(ConnectionUnauthorized::class);

it('rejects a malformed auth token with no colon', function () {
    $channel = new PrivateChannel('private-test-channel');

    // No `app-key:` prefix: Str::after returns the whole string, hash_equals fails.
    $channel->subscribe($this->connection, 'deadbeefdeadbeef');
})->throws(ConnectionUnauthorized::class);

it('rejects an auth token bound to a different socket_id', function () {
    $channel = new PrivateChannel('private-test-channel');

    // Compute auth for a fabricated socket_id 'A.1'.
    $auth = validAuth('A.1', 'private-test-channel');

    // But subscribe with a connection whose id is 'B.2'.
    $this->connection->id = 'B.2';

    $channel->subscribe($this->connection, $auth);
})->throws(ConnectionUnauthorized::class);

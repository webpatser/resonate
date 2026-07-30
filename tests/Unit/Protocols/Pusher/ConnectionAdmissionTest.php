<?php

use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\Server;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

/*
 * A connection is only "admitted" once it has passed the connection-limit and
 * origin checks and incremented the per-application counter. Two bugs lived
 * here:
 *
 *   1. A rejected connection was sent an error frame but never closed, and the
 *      handler entered its receive loop anyway, so a client that ignored the
 *      frame kept a fully working session. Origin allow-listing and
 *      max_connections were advisory only.
 *
 *   2. close() decremented the counter unconditionally, including for
 *      connections that never incremented it. Opening and dropping rejected
 *      connections therefore walked the count below the true number of live
 *      sockets and reset the quota for every tenant on the node.
 */

beforeEach(function () {
    $this->server = $this->app->make(Server::class);
    $this->channels = $this->app->make(ChannelManager::class);
});

function connectionCountFor(FakeConnection $connection): int
{
    return test()->channels->for($connection->app())->connectionCount();
}

it('admits a valid connection and counts it once', function () {
    $connection = new FakeConnection;

    expect($this->server->open($connection))->toBeTrue()
        ->and(connectionCountFor($connection))->toBe(1)
        ->and($connection->wasTerminated)->toBeFalse();
});

it('releases the count when an admitted connection closes', function () {
    $connection = new FakeConnection;

    $this->server->open($connection);
    expect(connectionCountFor($connection))->toBe(1);

    $this->server->close($connection);

    expect(connectionCountFor($connection))->toBe(0);
});

it('rejects and terminates a connection from a disallowed origin', function () {
    $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['example.com']);

    $connection = new FakeConnection(origin: 'https://evil.example.org');

    expect($this->server->open($connection))->toBeFalse()
        ->and($connection->wasTerminated)->toBeTrue()
        ->and(connectionCountFor($connection))->toBe(0);
});

it('does not decrement the count for a connection that was never admitted', function () {
    $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['example.com']);

    $admitted = new FakeConnection(origin: 'https://example.com');
    $this->server->open($admitted);

    expect(connectionCountFor($admitted))->toBe(1);

    // Ten rejected connections opened and torn down. Before the fix each close
    // decremented an increment that never happened.
    foreach (range(1, 10) as $i) {
        $rejected = new FakeConnection(origin: 'https://evil.example.org');

        $this->server->open($rejected);
        $this->server->close($rejected);
    }

    expect(connectionCountFor($admitted))->toBe(1);
});

it('keeps enforcing the connection limit after repeated rejections', function () {
    $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['example.com']);
    $this->app['config']->set('reverb.apps.apps.0.max_connections', 2);

    $first = new FakeConnection(origin: 'https://example.com');
    $second = new FakeConnection(origin: 'https://example.com');

    expect($this->server->open($first))->toBeTrue()
        ->and($this->server->open($second))->toBeTrue();

    foreach (range(1, 5) as $i) {
        $rejected = new FakeConnection(origin: 'https://evil.example.org');

        $this->server->open($rejected);
        $this->server->close($rejected);
    }

    // The limit must still apply: the counter was not eroded by the rejections.
    $third = new FakeConnection(origin: 'https://example.com');

    expect($this->server->open($third))->toBeFalse()
        ->and($third->wasTerminated)->toBeTrue();
});

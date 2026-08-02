<?php

use Webpatser\Resonate\Protocols\Pusher\Server;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

/*
 * Exercises the per-connection subscription cap and the channel name length
 * limit.
 *
 * `findOrCreate()` allocates a Channel for any name it is handed, and the
 * channel name was validated as `nullable|string` with no bound, so a single
 * connection could hold an unbounded number of channels with unbounded names.
 * Its eventual disconnect then walked every one of them in
 * `unsubscribeFromAll()`.
 */

beforeEach(function () {
    $this->server = $this->app->make(Server::class);
});

/**
 * Subscribe the given connection to a channel.
 */
function subscribeTo(FakeConnection $connection, string $channel): void
{
    test()->server->message($connection, json_encode([
        'event' => 'pusher:subscribe',
        'data' => ['channel' => $channel],
    ]));
}

/**
 * Determine whether the connection received the given pusher error message.
 */
function receivedError(FakeConnection $connection, string $message): bool
{
    return collect($connection->messages)->contains(
        fn (string $frame) => str_contains($frame, $message)
    );
}

it('rejects a subscribe once the connection is at its cap', function () {
    $this->app['config']->set('reverb.servers.reverb.max_subscriptions_per_connection', 2);

    $this->server->open($connection = new FakeConnection);

    subscribeTo($connection, 'public-one');
    subscribeTo($connection, 'public-two');

    expect(receivedError($connection, 'Subscription limit exceeded'))->toBeFalse();

    subscribeTo($connection, 'public-three');

    expect(receivedError($connection, 'Subscription limit exceeded'))->toBeTrue()
        // The rejected channel must not have been allocated: creating it first
        // and refusing afterwards would leave the allocation primitive intact.
        ->and(channels()->find('public-three'))->toBeNull();
});

it('still allows a re-subscribe to a channel the connection already holds', function () {
    $this->app['config']->set('reverb.servers.reverb.max_subscriptions_per_connection', 2);

    $this->server->open($connection = new FakeConnection);

    subscribeTo($connection, 'public-one');
    subscribeTo($connection, 'public-two');
    subscribeTo($connection, 'public-one');

    expect(receivedError($connection, 'Subscription limit exceeded'))->toBeFalse();
});

it('caps each connection separately', function () {
    $this->app['config']->set('reverb.servers.reverb.max_subscriptions_per_connection', 1);

    $this->server->open($first = new FakeConnection);
    $this->server->open($second = new FakeConnection);

    subscribeTo($first, 'public-one');
    subscribeTo($second, 'public-two');

    expect(receivedError($first, 'Subscription limit exceeded'))->toBeFalse()
        ->and(receivedError($second, 'Subscription limit exceeded'))->toBeFalse();
});

it('treats a cap of 0 as unlimited', function () {
    $this->app['config']->set('reverb.servers.reverb.max_subscriptions_per_connection', 0);

    $this->server->open($connection = new FakeConnection);

    for ($i = 0; $i < 20; $i++) {
        subscribeTo($connection, 'public-'.$i);
    }

    expect(receivedError($connection, 'Subscription limit exceeded'))->toBeFalse();
});

it('rejects a channel name longer than the configured maximum', function () {
    $this->app['config']->set('reverb.servers.reverb.max_channel_name_length', 32);

    $this->server->open($connection = new FakeConnection);

    $name = str_repeat('a', 33);

    subscribeTo($connection, $name);

    expect(receivedError($connection, 'Invalid message format'))->toBeTrue()
        ->and(channels()->find($name))->toBeNull();
});

it('accepts a channel name at the configured maximum', function () {
    $this->app['config']->set('reverb.servers.reverb.max_channel_name_length', 32);

    $this->server->open($connection = new FakeConnection);

    subscribeTo($connection, $name = str_repeat('a', 32));

    expect(receivedError($connection, 'Invalid message format'))->toBeFalse()
        ->and(channels()->find($name))->not->toBeNull();
});

it('treats a channel name length of 0 as unlimited', function () {
    $this->app['config']->set('reverb.servers.reverb.max_channel_name_length', 0);

    $this->server->open($connection = new FakeConnection);

    subscribeTo($connection, $name = str_repeat('a', 5_000));

    expect(channels()->find($name))->not->toBeNull();
});

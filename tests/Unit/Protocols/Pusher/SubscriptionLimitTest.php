<?php

use Illuminate\Support\Facades\Event;
use Webpatser\Resonate\Events\ChannelCreated;
use Webpatser\Resonate\Events\ChannelRemoved;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Webpatser\Resonate\Protocols\Pusher\Server;
use Webpatser\Resonate\Tests\Fakes\CountingChannelConnectionManager;
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

it('checks the cap at a cost that does not grow with the channels of the app', function () {
    $this->app->bind(ChannelConnectionManager::class, fn () => new CountingChannelConnectionManager);

    $this->server->open($first = new FakeConnection);
    $this->server->open($second = new FakeConnection);

    foreach ([$first, $second] as $i => $connection) {
        subscribeTo($connection, "held-{$i}-one");
        subscribeTo($connection, "held-{$i}-two");
    }

    CountingChannelConnectionManager::reset();
    subscribeTo($first, 'fresh-first');
    $few = CountingChannelConnectionManager::$lookups;

    $bystander = new FakeConnection;

    foreach (range(1, 100) as $i) {
        channels()->findOrCreate("busy-{$i}")->subscribe($bystander);
    }

    CountingChannelConnectionManager::reset();
    subscribeTo($second, 'fresh-second');

    expect(CountingChannelConnectionManager::$lookups)->toBe($few)
        ->and(channels()->find('fresh-second')->findById($second->id()))->not->toBeNull();
});

it('does not count a channel removed and recreated without the connection', function () {
    $this->app['config']->set('reverb.servers.reverb.max_subscriptions_per_connection', 2);

    $this->server->open($connection = new FakeConnection);

    subscribeTo($connection, 'public-one');
    subscribeTo($connection, 'public-gone');

    // Removed while the connection is still in it, then recreated without it.
    channels()->remove(channels()->find('public-gone'));
    channels()->findOrCreate('public-gone')->subscribe(new FakeConnection);

    subscribeTo($connection, 'public-two');

    expect(receivedError($connection, 'Subscription limit exceeded'))->toBeFalse();

    subscribeTo($connection, 'public-three');

    expect(receivedError($connection, 'Subscription limit exceeded'))->toBeTrue();
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

it('does not leave a channel behind when a private subscribe fails its signature', function () {
    $this->server->open($connection = new FakeConnection);

    subscribeTo($connection, 'private-orders');

    // Each refused subscribe used to leave an empty Channel registered under a
    // name of the client's choosing, outside the cap, which only counts the
    // channels the socket is actually in.
    expect(receivedError($connection, 'Connection is unauthorized'))->toBeTrue()
        ->and(channels()->find('private-orders'))->toBeNull();
});

it('does not leave a channel behind when a presence subscribe fails its signature', function () {
    $this->server->open($connection = new FakeConnection);

    $this->server->message($connection, json_encode([
        'event' => 'pusher:subscribe',
        'data' => [
            'channel' => 'presence-room',
            'auth' => 'app-key:deadbeef',
            'channel_data' => json_encode(['user_id' => '1']),
        ],
    ]));

    expect(receivedError($connection, 'Connection is unauthorized'))->toBeTrue()
        ->and(channels()->find('presence-room'))->toBeNull();
});

it('does not let failed subscribes accumulate channels past the cap', function () {
    $this->app['config']->set('reverb.servers.reverb.max_subscriptions_per_connection', 2);

    $this->server->open($connection = new FakeConnection);

    for ($i = 0; $i < 10; $i++) {
        subscribeTo($connection, 'private-flood-'.$i);
    }

    expect(channels()->all())->toBe([]);
});

it('keeps an existing channel when a further subscribe to it fails', function () {
    $this->server->open($member = new FakeConnection);
    $this->server->open($intruder = new FakeConnection);

    $this->server->message($member, json_encode([
        'event' => 'pusher:subscribe',
        'data' => ['channel' => 'private-orders', 'auth' => validAuth($member->id(), 'private-orders')],
    ]));

    subscribeTo($intruder, 'private-orders');

    expect(receivedError($intruder, 'Connection is unauthorized'))->toBeTrue()
        ->and(channels()->find('private-orders')?->findById($member->id()))->not->toBeNull();
});

it('keeps a channel a failed subscribe created when another connection joined it meanwhile', function () {
    $this->server->open($intruder = new FakeConnection);
    $member = new FakeConnection;

    // ChannelCreated is dispatched inside findOrCreate(), after the channel is
    // registered and before the failing subscribe verifies: the window in
    // which another connection can join the channel this call created.
    Event::listen(ChannelCreated::class, function (ChannelCreated $event) use ($member) {
        $event->channel->subscribe($member, validAuth($member->id(), 'private-orders'));
    });

    subscribeTo($intruder, 'private-orders');

    expect(receivedError($intruder, 'Connection is unauthorized'))->toBeTrue()
        ->and(channels()->find('private-orders')?->findById($member->id()))->not->toBeNull();
});

it('keeps a channel that was removed and recreated while a failed subscribe was in flight', function () {
    $this->server->open($intruder = new FakeConnection);
    $replacement = null;

    Event::listen(ChannelCreated::class, function (ChannelCreated $event) use (&$replacement) {
        if ($replacement !== null) {
            return;
        }

        $replacement = false;

        channels()->remove($event->channel);

        $replacement = channels()->findOrCreate('private-orders');
    });

    subscribeTo($intruder, 'private-orders');

    expect(receivedError($intruder, 'Connection is unauthorized'))->toBeTrue()
        ->and($replacement)->toBeInstanceOf(Channel::class)
        ->and(channels()->find('private-orders'))->toBe($replacement);
});

it('dispatches ChannelRemoved exactly once when a failed subscribe cleans up', function () {
    $this->server->open($connection = new FakeConnection);
    $removed = [];

    Event::listen(ChannelRemoved::class, function (ChannelRemoved $event) use (&$removed) {
        $removed[] = $event->channel->name();
    });

    subscribeTo($connection, 'private-orders');

    expect(receivedError($connection, 'Connection is unauthorized'))->toBeTrue()
        ->and($removed)->toBe(['private-orders'])
        ->and(channels()->find('private-orders'))->toBeNull();
});

it('treats a channel name length of 0 as unlimited', function () {
    $this->app['config']->set('reverb.servers.reverb.max_channel_name_length', 0);

    $this->server->open($connection = new FakeConnection);

    subscribeTo($connection, $name = str_repeat('a', 5_000));

    expect(channels()->find($name))->not->toBeNull();
});

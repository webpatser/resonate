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

    expect(receivedError($connection, 'Connection is over channel quota'))->toBeFalse();

    subscribeTo($connection, 'public-three');

    expect(receivedError($connection, 'Connection is over channel quota'))->toBeTrue()
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

    expect(receivedError($connection, 'Connection is over channel quota'))->toBeFalse();

    subscribeTo($connection, 'public-three');

    expect(receivedError($connection, 'Connection is over channel quota'))->toBeTrue();
});

it('still allows a re-subscribe to a channel the connection already holds', function () {
    $this->app['config']->set('reverb.servers.reverb.max_subscriptions_per_connection', 2);

    $this->server->open($connection = new FakeConnection);

    subscribeTo($connection, 'public-one');
    subscribeTo($connection, 'public-two');
    subscribeTo($connection, 'public-one');

    expect(receivedError($connection, 'Connection is over channel quota'))->toBeFalse();
});

it('caps each connection separately', function () {
    $this->app['config']->set('reverb.servers.reverb.max_subscriptions_per_connection', 1);

    $this->server->open($first = new FakeConnection);
    $this->server->open($second = new FakeConnection);

    subscribeTo($first, 'public-one');
    subscribeTo($second, 'public-two');

    expect(receivedError($first, 'Connection is over channel quota'))->toBeFalse()
        ->and(receivedError($second, 'Connection is over channel quota'))->toBeFalse();
});

it('treats a cap of 0 as unlimited', function () {
    $this->app['config']->set('reverb.servers.reverb.max_subscriptions_per_connection', 0);

    $this->server->open($connection = new FakeConnection);

    for ($i = 0; $i < 20; $i++) {
        subscribeTo($connection, 'public-'.$i);
    }

    expect(receivedError($connection, 'Connection is over channel quota'))->toBeFalse();
});

it('rejects a channel name longer than the configured maximum', function () {
    $this->app['config']->set('reverb.servers.reverb.max_channel_name_length', 32);

    $this->server->open($connection = new FakeConnection);

    $name = str_repeat('a', 33);

    subscribeTo($connection, $name);

    expect(receivedError($connection, 'Channel name is too long'))->toBeTrue()
        ->and(channels()->find($name))->toBeNull();
});

it('accepts a channel name at the configured maximum', function () {
    $this->app['config']->set('reverb.servers.reverb.max_channel_name_length', 32);

    $this->server->open($connection = new FakeConnection);

    subscribeTo($connection, $name = str_repeat('a', 32));

    expect(receivedError($connection, 'Channel name is too long'))->toBeFalse()
        ->and(receivedError($connection, 'Invalid message format'))->toBeFalse()
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

/**
 * Get the frames of the given event the connection received, decoded.
 *
 * @return list<array<string, mixed>>
 */
function framesOf(FakeConnection $connection, string $event): array
{
    return collect($connection->messages)
        ->map(fn (string $frame) => json_decode($frame, true))
        ->filter(fn (mixed $frame) => is_array($frame) && ($frame['event'] ?? null) === $event)
        ->values()
        ->all();
}

it('sends the over-quota refusal as a subscription error naming the channel', function () {
    $this->app['config']->set('reverb.servers.reverb.max_subscriptions_per_connection', 1);

    $this->server->open($connection = new FakeConnection);

    subscribeTo($connection, 'public-one');
    subscribeTo($connection, 'public-two');

    $connection->assertReceived([
        'event' => 'pusher:subscription_error',
        'data' => '{"type":"LimitReached","error":"Connection is over channel quota","status":4300}',
        'channel' => 'public-two',
    ]);

    expect(framesOf($connection, 'pusher:error'))->toBe([])
        ->and($connection->wasTerminated)->toBeFalse()
        ->and(channels()->find('public-two'))->toBeNull()
        ->and(channels()->find('public-one')?->findById($connection->id()))->not->toBeNull();
});

it('sends the over-long name refusal as a subscription error naming the channel', function () {
    $this->app['config']->set('reverb.servers.reverb.max_channel_name_length', 32);

    $this->server->open($connection = new FakeConnection);

    subscribeTo($connection, $name = str_repeat('a', 33));

    $connection->assertReceived([
        'event' => 'pusher:subscription_error',
        'data' => '{"type":"InvalidChannelName","error":"Channel name is too long","status":4300}',
        'channel' => $name,
    ]);

    expect(framesOf($connection, 'pusher:error'))->toBe([])
        ->and($connection->wasTerminated)->toBeFalse()
        ->and(channels()->find($name))->toBeNull()
        ->and(channels()->all())->toBe([]);
});

it('keeps the connection usable after a subscription error', function () {
    $this->app['config']->set('reverb.servers.reverb.max_channel_name_length', 32);

    $this->server->open($connection = new FakeConnection);

    subscribeTo($connection, str_repeat('a', 33));
    subscribeTo($connection, 'public-fine');

    $connection->assertReceived([
        'event' => 'pusher_internal:subscription_succeeded',
        'data' => '{}',
        'channel' => 'public-fine',
    ]);

    expect($connection->wasTerminated)->toBeFalse();
});

it('checks the name length before the quota', function () {
    $this->app['config']->set('reverb.servers.reverb.max_subscriptions_per_connection', 1);
    $this->app['config']->set('reverb.servers.reverb.max_channel_name_length', 32);

    $this->server->open($connection = new FakeConnection);

    subscribeTo($connection, 'public-one');
    subscribeTo($connection, $name = str_repeat('a', 33));

    $errors = framesOf($connection, 'pusher:subscription_error');

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['channel'])->toBe($name)
        ->and(json_decode($errors[0]['data'], true)['type'])->toBe('InvalidChannelName');
});

it('validates the subscribe payload before the name length', function () {
    $this->app['config']->set('reverb.servers.reverb.max_channel_name_length', 32);

    $this->server->open($connection = new FakeConnection);

    $this->server->message($connection, json_encode([
        'event' => 'pusher:subscribe',
        'data' => ['channel' => str_repeat('a', 33), 'channel_data' => 'not json'],
    ]));

    $connection->assertReceived([
        'event' => 'pusher:error',
        'data' => '{"code":4200,"message":"Invalid message format"}',
    ]);

    expect(framesOf($connection, 'pusher:subscription_error'))->toBe([])
        ->and(channels()->all())->toBe([]);
});

it('still confirms a re-subscribe to a held channel while at the cap', function () {
    $this->app['config']->set('reverb.servers.reverb.max_subscriptions_per_connection', 2);

    $this->server->open($connection = new FakeConnection);

    subscribeTo($connection, 'public-one');
    subscribeTo($connection, 'public-two');

    $before = count(framesOf($connection, 'pusher_internal:subscription_succeeded'));

    subscribeTo($connection, 'public-one');

    expect(framesOf($connection, 'pusher:subscription_error'))->toBe([])
        ->and(framesOf($connection, 'pusher_internal:subscription_succeeded'))->toHaveCount($before + 1)
        ->and(channels()->find('public-one')?->findById($connection->id()))->not->toBeNull();
});

it('accepts a new subscribe once an unsubscribe frees a slot', function () {
    $this->app['config']->set('reverb.servers.reverb.max_subscriptions_per_connection', 1);

    $this->server->open($connection = new FakeConnection);

    subscribeTo($connection, 'public-one');
    subscribeTo($connection, 'public-two');

    expect(framesOf($connection, 'pusher:subscription_error'))->toHaveCount(1);

    $this->server->message($connection, json_encode([
        'event' => 'pusher:unsubscribe',
        'data' => ['channel' => 'public-one'],
    ]));

    subscribeTo($connection, 'public-two');

    $connection->assertReceived([
        'event' => 'pusher_internal:subscription_succeeded',
        'data' => '{}',
        'channel' => 'public-two',
    ]);

    expect(framesOf($connection, 'pusher:subscription_error'))->toHaveCount(1)
        ->and(channels()->find('public-two')?->findById($connection->id()))->not->toBeNull();
});

it('keeps the unauthorized frame for a bad private signature and leaves no channel', function () {
    $this->server->open($connection = new FakeConnection);

    $this->server->message($connection, json_encode([
        'event' => 'pusher:subscribe',
        'data' => ['channel' => 'private-orders', 'auth' => 'app-key:deadbeef'],
    ]));

    $connection->assertReceived([
        'event' => 'pusher:error',
        'data' => '{"code":4009,"message":"Connection is unauthorized"}',
    ]);

    expect(framesOf($connection, 'pusher:subscription_error'))->toBe([])
        ->and(channels()->find('private-orders'))->toBeNull()
        ->and(channels()->all())->toBe([]);
});

it('treats a channel name length of 0 as unlimited', function () {
    $this->app['config']->set('reverb.servers.reverb.max_channel_name_length', 0);

    $this->server->open($connection = new FakeConnection);

    subscribeTo($connection, $name = str_repeat('a', 5_000));

    expect(channels()->find($name))->not->toBeNull();
});

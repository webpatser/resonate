<?php

use Webpatser\Resonate\Application;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Protocols\Pusher\ClientEvent;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

/*
 * The client event membership check reads channel state, and channel state is
 * kept per application. The lookup has to go through the sending connection's
 * own application: a check that consulted another application's channels
 * would let a connection in app B whisper by virtue of membership recorded
 * under app A, and would break whispers whenever two applications on one node
 * share a channel name (Reverb #418).
 */

beforeEach(function () {
    $apps = $this->app['config']->get('reverb.apps.apps');
    $apps[1] = array_merge($apps[0], ['app_id' => 'app-id-2', 'key' => 'app-key-2']);
    $this->app['config']->set('reverb.apps.apps', $apps);

    $this->first = app(ApplicationProvider::class)->findByKey('app-key');
    $this->second = app(ApplicationProvider::class)->findByKey('app-key-2');
});

/**
 * A fake connection that belongs to the second configured application.
 */
function secondAppConnection(): FakeConnection
{
    return new class extends FakeConnection
    {
        public function app(): Application
        {
            return app(ApplicationProvider::class)->findByKey('app-key-2');
        }
    };
}

/**
 * Subscribe a connection to a private channel under the given application.
 */
function subscribePrivate(Application $app, FakeConnection $connection, string $channel): void
{
    channels($app)->findOrCreate($channel)->subscribe($connection, validAuth($connection->id(), $channel));
}

it('rejects a client event whose sender is a member only under another application', function () {
    $member = new FakeConnection;
    subscribePrivate($this->first, $member, 'private-chat');

    // Membership for the sender exists, but only in the first application's
    // channel state; its own application has no such channel at all.
    $intruder = secondAppConnection();
    subscribePrivate($this->first, $intruder, 'private-chat');

    ClientEvent::handle($intruder, [
        'event' => 'client-typing',
        'channel' => 'private-chat',
        'data' => ['id' => 1],
    ]);

    $intruder->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4009,
            'message' => 'The client is not a member of the specified channel.',
        ]),
    ]);
    $member->assertNothingReceived();
});

it('keeps a whisper inside the sender application when another application shares the channel name', function () {
    $sender = new FakeConnection;
    $peer = new FakeConnection;
    subscribePrivate($this->first, $sender, 'private-chat');
    subscribePrivate($this->first, $peer, 'private-chat');

    $outsider = secondAppConnection();
    subscribePrivate($this->second, $outsider, 'private-chat');

    ClientEvent::handle($sender, [
        'event' => 'client-typing',
        'channel' => 'private-chat',
        'data' => ['id' => 1],
    ]);

    $peer->assertReceived([
        'event' => 'client-typing',
        'channel' => 'private-chat',
        'data' => ['id' => 1],
    ]);
    $outsider->assertNothingReceived();
    $sender->assertNothingReceived();
});

it('delivers a whisper from the second application while the first has the same channel', function () {
    $other = new FakeConnection;
    subscribePrivate($this->first, $other, 'private-chat');

    $sender = secondAppConnection();
    $peer = secondAppConnection();
    subscribePrivate($this->second, $sender, 'private-chat');
    subscribePrivate($this->second, $peer, 'private-chat');

    ClientEvent::handle($sender, [
        'event' => 'client-typing',
        'channel' => 'private-chat',
        'data' => ['id' => 2],
    ]);

    $peer->assertReceived([
        'event' => 'client-typing',
        'channel' => 'private-chat',
        'data' => ['id' => 2],
    ]);
    $other->assertNothingReceived();
});

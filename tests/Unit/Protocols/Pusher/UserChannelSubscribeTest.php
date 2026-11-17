<?php

use Webpatser\Resonate\Plugins\MessageDisposition;
use Webpatser\Resonate\Plugins\PluginContext;
use Webpatser\Resonate\Plugins\PluginManager;
use Webpatser\Resonate\Protocols\Pusher\EventHandler;
use Webpatser\Resonate\Protocols\Pusher\Exceptions\SubscriptionLimitExceeded;
use Webpatser\Resonate\Protocols\Pusher\Server;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\Fakes\FakeServerPlugin;

/*
 * Exercises admission to `#server-to-user-{id}` channels.
 *
 * The name carries no `private-` or `presence-` prefix, so it maps to a plain
 * public channel. Before core refused it, any client could subscribe to
 * another user's channel and read that user's direct messages whenever
 * resonate-users (whose interceptor refuses it) was not installed.
 */

beforeEach(function () {
    $this->server = $this->app->make(Server::class);
});

/**
 * Send a client `pusher:subscribe` for the given channel.
 */
function clientSubscribesToUserChannel(FakeConnection $connection, string $channel): void
{
    test()->server->message($connection, json_encode([
        'event' => 'pusher:subscribe',
        'data' => ['channel' => $channel],
    ]));
}

/**
 * Determine whether the connection received a `pusher:error` with code 4009.
 */
function receivedUserChannelRefusal(FakeConnection $connection): bool
{
    return collect($connection->messages)->contains(function (string $frame) {
        $payload = json_decode($frame, true);

        return ($payload['event'] ?? null) === 'pusher:error'
            && (json_decode($payload['data'] ?? '', true)['code'] ?? null) === 4009;
    });
}

/**
 * Determine whether the connection received a frame containing the given text.
 */
function userChannelFrameContains(FakeConnection $connection, string $text): bool
{
    return collect($connection->messages)->contains(
        fn (string $frame) => str_contains($frame, $text)
    );
}

it('refuses a client subscribe to a user channel', function () {
    $this->server->open($connection = new FakeConnection);

    clientSubscribesToUserChannel($connection, '#server-to-user-42');

    expect(receivedUserChannelRefusal($connection))->toBeTrue()
        ->and(userChannelFrameContains($connection, 'subscription_succeeded'))->toBeFalse()
        ->and(channels()->find('#server-to-user-42'))->toBeNull();
});

it('refuses a client subscribe to a user channel another connection already joined', function () {
    $this->server->open($owner = new FakeConnection);
    $this->server->open($intruder = new FakeConnection);

    app(PluginContext::class)->joinUserChannel($owner, '42');

    clientSubscribesToUserChannel($intruder, '#server-to-user-42');

    expect(receivedUserChannelRefusal($intruder))->toBeTrue()
        ->and(channels()->find('#server-to-user-42')?->findById($intruder->id()))->toBeNull();
});

it('refuses a client subscribe to a user channel with channel name validation disabled', function () {
    $this->app['config']->set('reverb.servers.reverb.validate_channel_names', false);

    $this->server->open($connection = new FakeConnection);

    clientSubscribesToUserChannel($connection, '#server-to-user-42');

    expect(receivedUserChannelRefusal($connection))->toBeTrue()
        ->and(channels()->find('#server-to-user-42'))->toBeNull();
});

it('refuses a client subscribe to a user channel ahead of the plugin interceptors', function (MessageDisposition $disposition) {
    $plugin = new FakeServerPlugin($disposition);
    app(PluginManager::class)->register($plugin);

    $this->server->open($connection = new FakeConnection);

    clientSubscribesToUserChannel($connection, '#server-to-user-42');

    expect(receivedUserChannelRefusal($connection))->toBeTrue()
        ->and($plugin->messages)->toBe([])
        ->and(channels()->find('#server-to-user-42'))->toBeNull();
})->with([
    'relay' => MessageDisposition::Relay,
    'handled' => MessageDisposition::Handled,
]);

it('admits a user channel subscribe a plugin hands straight to the event handler', function () {
    $this->server->open($connection = new FakeConnection);

    // The path released resonate-users 1.0.0 takes on signin: a subscribe
    // handed to the handler directly, never a client frame through the server.
    app(EventHandler::class)->handle($connection, 'pusher:subscribe', [
        'channel' => '#server-to-user-42',
        'channel_data' => json_encode(['user_id' => '42']),
    ]);

    $member = channels()->find('#server-to-user-42')?->findById($connection->id());

    expect($member)->not->toBeNull()
        ->and($member->data('user_id'))->toBe('42');
});

it('joins a connection to its own user channel through the plugin context', function () {
    $this->server->open($connection = new FakeConnection);

    app(PluginContext::class)->joinUserChannel($connection, '42');

    $member = channels()->find('#server-to-user-42')?->findById($connection->id());

    expect($member)->not->toBeNull()
        ->and($member->data('user_id'))->toBe('42')
        ->and(userChannelFrameContains($connection, 'pusher_internal:subscription_succeeded'))->toBeTrue();
});

it('applies the subscription cap to a user channel join', function () {
    $this->app['config']->set('reverb.servers.reverb.max_subscriptions_per_connection', 1);

    $this->server->open($connection = new FakeConnection);

    clientSubscribesToUserChannel($connection, 'orders');

    expect(fn () => app(PluginContext::class)->joinUserChannel($connection, '42'))
        ->toThrow(SubscriptionLimitExceeded::class)
        ->and(channels()->find('#server-to-user-42'))->toBeNull();
});

it('still accepts a client subscribe to an ordinary public channel', function () {
    $this->server->open($connection = new FakeConnection);

    clientSubscribesToUserChannel($connection, 'server-to-user-42');

    expect(receivedUserChannelRefusal($connection))->toBeFalse()
        ->and(channels()->find('server-to-user-42'))->not->toBeNull();
});

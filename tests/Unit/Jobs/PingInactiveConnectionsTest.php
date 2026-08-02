<?php

use Webpatser\Resonate\Jobs\PingInactiveConnections;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;

/*
 * The job used to walk channel membership, which only ever contains connections
 * that sent `pusher:subscribe`. A connection that completed the handshake and
 * subscribed to nothing was therefore never pinged, so it never became stale
 * and never got pruned, while still holding a slot against `max_connections`.
 * The job now walks the open-connection list, which is populated at admission.
 */

it('pings inactive connections', function () {
    $inactive = openConnection('updates');
    $active = openConnection('updates');

    // Force the first connection past its ping interval; the second stays fresh.
    $inactive->setLastSeenAt(0);
    $active->setLastSeenAt(time());

    (new PingInactiveConnections)->handle(app(ChannelManager::class));

    $inactive->assertHasBeenPinged();
    expect($inactive->messages[0])->toContain('pusher:ping');

    expect($active->messages)->toBeEmpty();
});

it('pings an inactive connection that never subscribed to a channel', function () {
    $lurker = openConnection();

    expect(channels()->connections())->toBeEmpty();

    $lurker->setLastSeenAt(0);

    (new PingInactiveConnections)->handle(app(ChannelManager::class));

    $lurker->assertHasBeenPinged();
    expect($lurker->messages[0])->toContain('pusher:ping');
});

it('does nothing when there are no inactive connections', function () {
    $connection = openConnection('updates');
    $connection->setLastSeenAt(time());

    (new PingInactiveConnections)->handle(app(ChannelManager::class));

    expect($connection->messages)->toBeEmpty();
});

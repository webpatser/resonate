<?php

use Illuminate\Support\Facades\Event;
use Webpatser\Resonate\Events\ConnectionPruned;
use Webpatser\Resonate\Jobs\PruneStaleConnections;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;

/*
 * Pruning used to walk channel membership, so a connection that completed the
 * handshake and never sent `pusher:subscribe` was unreachable: it could not go
 * stale and could not be pruned, and it held its slot against `max_connections`
 * for the lifetime of the process. Pruning now walks the open-connection list
 * and releases the slot as part of the sweep.
 */

it('prunes stale connections, sends a 4201 error frame, and fires ConnectionPruned', function () {
    Event::fake([ConnectionPruned::class]);

    $stale = openConnection('updates');

    // A stale connection is one that was pinged but did not respond in time.
    $stale->setLastSeenAt(0);
    $stale->setHasBeenPinged();

    (new PruneStaleConnections)->handle(app(ChannelManager::class));

    expect($stale->wasTerminated)->toBeTrue()
        ->and($stale->messages[0])->toContain('pusher:error')
        ->and($stale->messages[0])->toContain('4201')
        ->and($stale->messages[0])->toContain('Pong reply not received in time');

    Event::assertDispatched(ConnectionPruned::class);
});

it('prunes a stale connection that never subscribed to a channel', function () {
    Event::fake([ConnectionPruned::class]);

    $lurker = openConnection();

    expect(channels()->connections())->toBeEmpty()
        ->and(channels()->connectionCount())->toBe(1);

    $lurker->setLastSeenAt(0);
    $lurker->setHasBeenPinged();

    (new PruneStaleConnections)->handle(app(ChannelManager::class));

    expect($lurker->wasTerminated)->toBeTrue()
        ->and($lurker->messages[0])->toContain('4201');

    Event::assertDispatched(ConnectionPruned::class);
});

it('releases the slot held by a pruned connection', function () {
    $stale = openConnection();
    $stale->setLastSeenAt(0);
    $stale->setHasBeenPinged();

    openConnection()->setLastSeenAt(time());

    expect(channels()->connectionCount())->toBe(2);

    (new PruneStaleConnections)->handle(app(ChannelManager::class));

    expect(channels()->connectionCount())->toBe(1)
        ->and(channels()->openConnections())->not->toHaveKey($stale->id());
});

it('dispatches the pruned connection itself as the event payload', function () {
    Event::fake([ConnectionPruned::class]);

    $stale = openConnection();
    $stale->setLastSeenAt(0);
    $stale->setHasBeenPinged();

    (new PruneStaleConnections)->handle(app(ChannelManager::class));

    Event::assertDispatched(
        ConnectionPruned::class,
        fn (ConnectionPruned $event) => $event->connection === $stale
    );
});

it('leaves active connections alone', function () {
    Event::fake([ConnectionPruned::class]);

    $connection = openConnection('updates');
    $connection->setLastSeenAt(time());

    (new PruneStaleConnections)->handle(app(ChannelManager::class));

    expect($connection->wasTerminated)->toBeFalse()
        ->and($connection->messages)->toBeEmpty();

    Event::assertNotDispatched(ConnectionPruned::class);
});

<?php

use Illuminate\Support\Facades\Event;
use Webpatser\Resonate\Events\ConnectionPruned;
use Webpatser\Resonate\Jobs\PruneStaleConnections;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

it('prunes stale connections, sends a 4201 error frame, and fires ConnectionPruned', function () {
    Event::fake([ConnectionPruned::class]);

    channels()->findOrCreate('updates')->subscribe($stale = new FakeConnection);

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

it('leaves active connections alone', function () {
    Event::fake([ConnectionPruned::class]);

    channels()->findOrCreate('updates')->subscribe($connection = new FakeConnection);
    $connection->setLastSeenAt(time());

    (new PruneStaleConnections)->handle(app(ChannelManager::class));

    expect($connection->wasTerminated)->toBeFalse()
        ->and($connection->messages)->toBeEmpty();

    Event::assertNotDispatched(ConnectionPruned::class);
});

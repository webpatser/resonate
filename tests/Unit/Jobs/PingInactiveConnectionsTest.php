<?php

use Webpatser\Resonate\Jobs\PingInactiveConnections;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

it('pings inactive connections', function () {
    channels()->findOrCreate('updates')->subscribe($inactive = new FakeConnection);
    channels()->findOrCreate('updates')->subscribe($active = new FakeConnection);

    // Force the first connection past its ping interval; the second stays fresh.
    $inactive->setLastSeenAt(0);
    $active->setLastSeenAt(time());

    (new PingInactiveConnections)->handle(app(ChannelManager::class));

    $inactive->assertHasBeenPinged();
    expect($inactive->messages[0])->toContain('pusher:ping');

    expect($active->messages)->toBeEmpty();
});

it('does nothing when there are no inactive connections', function () {
    channels()->findOrCreate('updates')->subscribe($connection = new FakeConnection);
    $connection->setLastSeenAt(time());

    (new PingInactiveConnections)->handle(app(ChannelManager::class));

    expect($connection->messages)->toBeEmpty();
});

<?php

use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;
use Webpatser\Resonate\Protocols\Pusher\Channels\ChannelConnection;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

/*
 * The fan-out loop called send() on each subscriber with no per-connection
 * guard. A peer that drops between the loop reading the connection list and
 * the write throws from deep inside the transport, which aborted the whole
 * broadcast: every subscriber after the dead one silently missed the event,
 * and the publisher got back a misleading 4200 "Invalid message format".
 */

final class ExplodingConnection extends FakeConnection
{
    public function send(string $message): void
    {
        throw new RuntimeException('peer went away mid-write');
    }
}

function channelWithConnections(array $connections): Channel
{
    $manager = Mockery::mock(ChannelConnectionManager::class);
    $manager->shouldReceive('for')->andReturnSelf();
    $manager->shouldReceive('all')->andReturn($connections);

    app()->instance(ChannelConnectionManager::class, $manager);

    return new Channel('test-channel');
}

it('keeps broadcasting to the remaining subscribers when one peer is gone', function () {
    $first = new FakeConnection;
    $dead = new ExplodingConnection;
    $last = new FakeConnection;

    $channel = channelWithConnections([
        $first->id() => new ChannelConnection($first),
        $dead->id() => new ChannelConnection($dead),
        $last->id() => new ChannelConnection($last),
    ]);

    $channel->broadcastToAll(['event' => 'update']);

    // The subscriber after the dead one used to receive nothing at all.
    expect($first->messages)->toHaveCount(1)
        ->and($last->messages)->toHaveCount(1);
});

it('does not let a dead peer surface as an error to the publisher', function () {
    $dead = new ExplodingConnection;
    $other = new FakeConnection;

    $channel = channelWithConnections([
        $dead->id() => new ChannelConnection($dead),
        $other->id() => new ChannelConnection($other),
    ]);

    // Previously this threw, and Server::error() turned it into a 4200 reply.
    $channel->broadcastToAll(['event' => 'update']);

    expect($other->messages)->toHaveCount(1);
});

it('still excludes the sender while isolating failures', function () {
    $sender = new FakeConnection;
    $dead = new ExplodingConnection;
    $other = new FakeConnection;

    $channel = channelWithConnections([
        $sender->id() => new ChannelConnection($sender),
        $dead->id() => new ChannelConnection($dead),
        $other->id() => new ChannelConnection($other),
    ]);

    $channel->broadcast(['event' => 'update'], $sender);

    expect($sender->messages)->toBeEmpty()
        ->and($other->messages)->toHaveCount(1);
});

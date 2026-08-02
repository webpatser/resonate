<?php

use Fledge\Async\WebSocket\WebsocketCloseCode;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Server\RawConnection;
use Webpatser\Resonate\Server\WebSocketConnection;
use Webpatser\Resonate\Tests\Fakes\FakeWebsocketClient;

use function Fledge\Async\async;

/*
 * A channel fan-out used to write to its subscribers one at a time, and each
 * write awaited the socket. A peer that stopped reading (TCP zero window) then
 * suspended the broadcasting fiber, so every subscriber after it in the loop
 * waited on a client that had no intention of reading: a near-free channel-wide
 * denial of service.
 *
 * Each connection now owns a bounded queue drained by a single writer fiber, so
 * a stalled peer holds up only its own writer while its ordering guarantee (the
 * one thing `async()` per send would have destroyed) still holds exactly.
 */

/**
 * Build a protocol connection over a fake socket.
 */
function fakeSocketConnection(FakeWebsocketClient $client, ?int $maxQueueSize = null): WebSocketConnection
{
    return new WebSocketConnection(
        new RawConnection($client, $maxQueueSize ?? RawConnection::DEFAULT_MAX_QUEUE_SIZE),
        app(ApplicationProvider::class)->findByKey('app-key'),
        'http://localhost',
    );
}

it('writes messages in the order they were queued, whatever the socket does', function () {
    $client = (new FakeWebsocketClient)->block();
    $connection = fakeSocketConnection($client);

    $queued = [];
    $fibers = [];

    // Ten fibers racing to write to one connection. Without a single writer
    // these interleave, and a client seeing `member_removed` before its
    // `member_added` is worse than the stall being fixed here.
    foreach (range(1, 10) as $index) {
        $fibers[] = async(function () use ($connection, &$queued, $index) {
            $queued[] = "message-{$index}";

            $connection->send("message-{$index}");
        });
    }

    drainLoop();

    expect($client->sent)->toBe([])
        ->and($client->blockedWrites)->toBe(1);

    $client->release();

    drainLoop();

    expect($client->sent)->toBe($queued)->toHaveCount(10);
});

it('keeps pings in sequence with the messages around them', function () {
    $client = (new FakeWebsocketClient)->block();
    $connection = fakeSocketConnection($client);

    $connection->send('first');
    $connection->control();
    $connection->send('second');

    $client->release();

    drainLoop();

    expect($client->writes)->toBe(['first', FakeWebsocketClient::PING, 'second']);
});

it('delivers a broadcast to every other subscriber while one is not reading', function () {
    $stalled = (new FakeWebsocketClient(1))->block();
    $first = new FakeWebsocketClient(2);
    $second = new FakeWebsocketClient(3);

    $channel = channels()->findOrCreate('test-channel');

    // The stalled peer subscribes first, so before the queue it was the one
    // holding up everybody behind it in the fan-out loop.
    $channel->subscribe(fakeSocketConnection($stalled));
    $channel->subscribe(fakeSocketConnection($first));
    $channel->subscribe(fakeSocketConnection($second));

    // Reaching the line after this one is itself the assertion: a broadcast
    // that suspended on the stalled peer would suspend {main}, which Revolt
    // fails outright rather than silently returning.
    $channel->broadcast(['event' => 'update', 'channel' => 'test-channel']);

    drainLoop();

    $payload = json_encode(['event' => 'update', 'channel' => 'test-channel']);

    expect($first->sent)->toBe([$payload])
        ->and($second->sent)->toBe([$payload])
        ->and($stalled->sent)->toBe([])
        ->and($stalled->blockedWrites)->toBe(1);

    // And the stalled peer still gets its copy, in order, once it reads again.
    $stalled->release();

    drainLoop();

    expect($stalled->sent)->toBe([$payload]);
});

it('closes a connection that falls behind the configured bound', function () {
    $client = (new FakeWebsocketClient)->block();
    $connection = fakeSocketConnection($client, maxQueueSize: 3);

    $connection->send('in-flight');

    drainLoop();

    // The first write is suspended on the socket, so the bound applies to what
    // is waiting behind it.
    expect($client->blockedWrites)->toBe(1);

    foreach (range(1, 3) as $index) {
        $connection->send("queued-{$index}");
    }

    expect($client->isClosed())->toBeFalse();

    $connection->send('over-the-limit');

    drainLoop();

    expect($client->isClosed())->toBeTrue()
        ->and($client->closedWith['code'])->toBe(WebsocketCloseCode::TRY_AGAIN_LATER)
        ->and($client->sent)->toBe([]);

    // Nothing is queued for a dropped peer, and nothing new is accepted.
    $connection->send('after-close');

    drainLoop();

    expect($client->sent)->toBe([]);
});

it('leaves no writer fiber behind when a connection closes normally', function () {
    $client = new FakeWebsocketClient;
    $raw = new RawConnection($client);
    $connection = new WebSocketConnection(
        $raw,
        app(ApplicationProvider::class)->findByKey('app-key'),
        'http://localhost',
    );

    $connection->send('first');
    $connection->send('second');
    $connection->terminate();

    drainLoop();

    expect($client->sent)->toBe(['first', 'second'])
        ->and($client->closedWith['code'])->toBe(WebsocketCloseCode::NORMAL_CLOSE)
        ->and($raw->outbound()->isDraining())->toBeFalse()
        ->and($raw->outbound()->size())->toBe(0)
        ->and($raw->outbound()->isAccepting())->toBeFalse();
});

it('leaves no writer fiber behind when the peer disappears mid-write', function () {
    $client = (new FakeWebsocketClient)->block();
    $raw = new RawConnection($client);
    $connection = new WebSocketConnection(
        $raw,
        app(ApplicationProvider::class)->findByKey('app-key'),
        'http://localhost',
    );

    $connection->send('first');
    $connection->send('second');

    drainLoop();

    expect($raw->outbound()->isDraining())->toBeTrue();

    // The peer goes away while the writer is suspended inside the write.
    $client->fail();
    $client->release();

    drainLoop();

    expect($raw->outbound()->isDraining())->toBeFalse()
        ->and($raw->outbound()->size())->toBe(0)
        ->and($raw->outbound()->isAccepting())->toBeFalse()
        ->and($client->closedWith['code'])->toBe(WebsocketCloseCode::ABNORMAL_CLOSE);

    // A dead connection does not start another writer.
    $connection->send('third');

    drainLoop();

    expect($raw->outbound()->isDraining())->toBeFalse()
        ->and($client->sent)->toBe([]);
});

it('behaves exactly as before for a consumer that keeps up', function () {
    $client = new FakeWebsocketClient;
    $raw = new RawConnection($client);
    $connection = new WebSocketConnection(
        $raw,
        app(ApplicationProvider::class)->findByKey('app-key'),
        'http://localhost',
    );

    foreach (range(1, 25) as $index) {
        $connection->send("message-{$index}");
    }

    drainLoop();

    expect($client->sent)->toHaveCount(25)
        ->and($client->sent[0])->toBe('message-1')
        ->and($client->sent[24])->toBe('message-25')
        ->and($client->isClosed())->toBeFalse()
        ->and($raw->outbound()->isDraining())->toBeFalse();
});

it('sends a rejection frame before closing the socket behind it', function () {
    $client = new FakeWebsocketClient;
    $connection = fakeSocketConnection($client);

    // What Server::open() does with a connection it refuses: error frame first,
    // terminate immediately after. The close has to stay behind the frame.
    $connection->send('{"event":"pusher:error"}');
    $connection->terminate();

    drainLoop();

    expect($client->sent)->toBe(['{"event":"pusher:error"}'])
        ->and($client->closedWith['code'])->toBe(WebsocketCloseCode::NORMAL_CLOSE);
});

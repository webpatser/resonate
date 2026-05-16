<?php

use Fledge\Async\WebSocket\Client as WebSocketClient;
use Revolt\EventLoop;
use Webpatser\Resonate\Server\Factory;

/**
 * Phase 1 milestone: boot the server on an ephemeral port and round-trip a real
 * WebSocket client through the fledge-fiber transport and the Pusher protocol,
 * the same path a laravel-echo / pusher-js client takes.
 */
it('completes the Pusher connection and public-channel subscribe handshake', function () {
    $port = random_int(20000, 40000);

    $server = Factory::make(host: '127.0.0.1', port: $port);

    $received = [];
    $failure = null;

    // Watchdog: never let a broken handshake hang the suite.
    $watchdog = EventLoop::delay(5.0, function () use (&$failure, $server) {
        $failure = 'timed out waiting for the server round-trip';
        $server->stop();
    });

    EventLoop::queue(function () use ($port, &$received, &$failure, $server, $watchdog) {
        try {
            // Give the accept loop a tick to bind the socket.
            \Fledge\Async\delay(0.1);

            $connection = WebSocketClient\connect("ws://127.0.0.1:{$port}/app/app-key");

            $established = json_decode($connection->receive()->buffer(), true);
            $received['established'] = $established;

            $connection->sendText(json_encode([
                'event' => 'pusher:subscribe',
                'data' => ['channel' => 'public-channel'],
            ]));

            $subscribed = json_decode($connection->receive()->buffer(), true);
            $received['subscribed'] = $subscribed;

            $connection->close();
        } catch (\Throwable $e) {
            $failure = $e->getMessage();
        } finally {
            EventLoop::cancel($watchdog);
            $server->stop();
        }
    });

    $server->start();

    expect($failure)->toBeNull()
        ->and($received['established']['event'])->toBe('pusher:connection_established')
        ->and(json_decode($received['established']['data'], true))
        ->toHaveKey('socket_id')
        ->toHaveKey('activity_timeout')
        ->and($received['subscribed']['event'])->toBe('pusher_internal:subscription_succeeded')
        ->and($received['subscribed']['channel'])->toBe('public-channel');
})->group('integration');

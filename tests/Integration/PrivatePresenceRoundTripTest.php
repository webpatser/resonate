<?php

use Fledge\Async\WebSocket\Client as WebSocketClient;
use Revolt\EventLoop;
use Webpatser\Resonate\Server\Factory;

/**
 * Phase 2 milestone: round-trip a real WebSocket client through the fledge-fiber
 * transport for private and presence channels, proving the WS HMAC auth and the
 * presence member payload work end-to-end, the same path laravel-echo takes.
 */
it('completes private and presence channel subscribe handshakes', function () {
    $port = random_int(20000, 40000);
    $secret = 'app-secret';

    $server = Factory::make(host: '127.0.0.1', port: $port);

    $received = [];
    $failure = null;

    $watchdog = EventLoop::delay(5.0, function () use (&$failure, $server) {
        $failure = 'timed out waiting for the server round-trip';
        $server->stop();
    });

    EventLoop::queue(function () use ($port, $secret, &$received, &$failure, $server, $watchdog) {
        try {
            \Fledge\Async\delay(0.1);

            $connection = WebSocketClient\connect("ws://127.0.0.1:{$port}/app/app-key");

            $established = json_decode($connection->receive()->buffer(), true);
            $socketId = json_decode($established['data'], true)['socket_id'];

            // --- Private channel: auth signs "{socket_id}:{channel}" ---
            $privateChannel = 'private-orders';
            $privateAuth = 'app-key:'.hash_hmac('sha256', "{$socketId}:{$privateChannel}", $secret);

            $connection->sendText(json_encode([
                'event' => 'pusher:subscribe',
                'data' => ['channel' => $privateChannel, 'auth' => $privateAuth],
            ]));
            $received['private'] = json_decode($connection->receive()->buffer(), true);

            // --- Presence channel: auth signs "{socket_id}:{channel}:{channel_data}" ---
            $presenceChannel = 'presence-room';
            $channelData = json_encode(['user_id' => '1', 'user_info' => ['name' => 'Christoph']]);
            $presenceAuth = 'app-key:'.hash_hmac('sha256', "{$socketId}:{$presenceChannel}:{$channelData}", $secret);

            $connection->sendText(json_encode([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => $presenceChannel,
                    'auth' => $presenceAuth,
                    'channel_data' => $channelData,
                ],
            ]));
            $received['presence'] = json_decode($connection->receive()->buffer(), true);

            // --- Rejected: a private subscribe with a bad signature ---
            $connection->sendText(json_encode([
                'event' => 'pusher:subscribe',
                'data' => ['channel' => 'private-denied', 'auth' => 'app-key:deadbeef'],
            ]));
            $received['rejected'] = json_decode($connection->receive()->buffer(), true);

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
        ->and($received['private']['event'])->toBe('pusher_internal:subscription_succeeded')
        ->and($received['private']['channel'])->toBe('private-orders')
        ->and($received['presence']['event'])->toBe('pusher_internal:subscription_succeeded')
        ->and($received['presence']['channel'])->toBe('presence-room')
        ->and(json_decode($received['presence']['data'], true))
        ->toHaveKey('presence')
        ->and(json_decode($received['presence']['data'], true)['presence']['ids'])->toContain('1')
        ->and($received['rejected']['event'])->toBe('pusher:error');
})->group('integration');

<?php

use Fledge\Async\TimeoutCancellation;
use Fledge\Async\WebSocket\Client\WebsocketConnection;
use Fledge\Async\WebSocket\WebsocketCloseCode;
use Webpatser\Resonate\Tests\Support\ServerProcess;

use function Fledge\Async\WebSocket\Client\connect;

/*
 * Graceful shutdown of a real `resonate:start` process with open connections,
 * on both event loop drivers. SIGTERM and SIGINT go through the command's
 * signal handler, which defers the stop onto the loop: the listener closes,
 * every client gets a 1001 close frame, the loop stops, the command removes
 * its PID file and the process exits 0. With clients that answer the close
 * frame that takes milliseconds; a client that never answers holds the stop
 * for the WebSocket close period (3 seconds).
 */

beforeEach(function () {
    if (! function_exists('posix_kill') || ! function_exists('pcntl_signal')) {
        $this->markTestSkipped('Needs the posix and pcntl extensions.');
    }
});

it('stops gracefully on a signal with clients connected', function (string $driver, int $signal) {
    $server = ServerProcess::start($driver);
    $clients = [];

    try {
        $deadline = new TimeoutCancellation(15.0, 'Timed out talking to the server.');

        foreach (range(1, 3) as $ignored) {
            $client = connect("ws://127.0.0.1:{$server->port}/app/app-key", $deadline);
            $client->receive($deadline)?->buffer(); // pusher:connection_established
            $client->sendText(json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => 'news']]));
            $client->receive($deadline)?->buffer(); // subscription_succeeded
            $clients[] = $client;
        }

        $sent = microtime(true);
        $server->signal($signal);

        // receive() returning null means the close frame arrived; the reply
        // is written by the loop, which wait() keeps running.
        foreach ($clients as $client) {
            expect($client->receive($deadline))->toBeNull();
        }

        $exitCode = $server->wait(10.0);
        $elapsed = microtime(true) - $sent;

        expect($exitCode)->toBe(0, $server->log())
            ->and($elapsed)->toBeLessThan(1.0)
            ->and(array_map(fn (WebsocketConnection $client) => $client->getCloseInfo()->getCode(), $clients))
            ->each->toBe(WebsocketCloseCode::GOING_AWAY)
            ->and(is_file("{$server->storage}/resonate.pid"))->toBeFalse();
    } finally {
        foreach ($clients as $client) {
            $client->isClosed() || $client->close();
        }

        $server->stop();
    }
})->with([
    'poll' => 'poll',
    'select' => 'select',
])->with([
    'SIGTERM' => fn () => SIGTERM,
    'SIGINT' => fn () => SIGINT,
])->group('integration');

it('waits out the close period for a client that never answers the close, then exits 0', function (string $driver) {
    $server = ServerProcess::start($driver);
    $socket = null;

    try {
        // A raw client outside the loop: it upgrades, then never reads again,
        // so it never answers the server's close frame.
        $socket = stream_socket_client("tcp://127.0.0.1:{$server->port}", $errno, $errstr, 5);
        expect($socket)->not->toBeFalse($errstr);

        fwrite($socket, "GET /app/app-key HTTP/1.1\r\nHost: 127.0.0.1:{$server->port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
        stream_set_timeout($socket, 5);

        $response = '';
        while (! str_contains($response, 'pusher:connection_established') && ! feof($socket)) {
            $response .= (string) fread($socket, 8192);
        }

        expect($response)->toStartWith('HTTP/1.1 101')
            ->toContain('pusher:connection_established');

        $sent = microtime(true);
        $server->signal(SIGTERM);

        $exitCode = $server->wait(10.0);
        $elapsed = microtime(true) - $sent;

        expect($exitCode)->toBe(0, $server->log())
            ->and($elapsed)->toBeGreaterThan(2.5)
            ->and($elapsed)->toBeLessThan(5.0);
    } finally {
        is_resource($socket) && fclose($socket);

        $server->stop();
    }
})->with([
    'poll' => 'poll',
    'select' => 'select',
])->group('integration');

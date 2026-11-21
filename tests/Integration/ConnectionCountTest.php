<?php

use Fledge\Async\TimeoutCancellation;
use Fledge\Async\WebSocket\Client\WebsocketConnection;
use Fledge\Loop\DriverFactory;
use GuzzleHttp\Client as GuzzleClient;
use Pusher\Pusher;
use Webpatser\Resonate\Tests\Support\ServerProcess;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;
use function Fledge\Async\WebSocket\Client\connect;

/*
 * The poll driver has no FD_SETSIZE ceiling, so one node holds more than 1024
 * connections. A real `resonate:start` process on the poll driver accepts
 * CLIENTS WebSocket clients held open at the same time, every one of them
 * receives a broadcast sent through the HTTP API, and all of them close
 * cleanly. The clients live in this process and the server in its own, so
 * each process holds about CLIENTS descriptors; both must be allowed
 * DESCRIPTORS, which the test raises the soft limit to (the child inherits it).
 *
 * Under FLEDGE_EVENT_LOOP=select this process cannot hold the clients, so the
 * test is skipped there; the select driver's FD_SETSIZE error is fledge-loop's
 * own, tested there.
 */

const CLIENTS = 1_100;
const DESCRIPTORS = 4_096;
const BATCH = 50;

beforeEach(function () {
    if (! function_exists('posix_setrlimit') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the posix extension to raise the open file limit.');
    }

    if (DriverFactory::resolve() !== 'poll') {
        $this->markTestSkipped('The stream_select driver cannot watch descriptors above FD_SETSIZE (1024); this test needs the poll driver.');
    }

    if (trim((string) shell_exec("pgrep -f 'php .*idle_connections' 2>/dev/null")) !== '') {
        $this->markTestSkipped('A benchmark holding loopback sockets is running.');
    }

    [$soft, $hard] = posix_getrlimit(POSIX_RLIMIT_NOFILE);

    if ($soft === 'unlimited' || (int) $soft >= DESCRIPTORS) {
        return;
    }

    if (($hard === 'unlimited' || (int) $hard >= DESCRIPTORS)
        && posix_setrlimit(POSIX_RLIMIT_NOFILE, DESCRIPTORS, $hard === 'unlimited' ? POSIX_RLIM_INFINITY : (int) $hard)) {
        return;
    }

    $this->markTestSkipped(sprintf('Cannot raise the open file limit to %d (soft %s, hard %s).', DESCRIPTORS, $soft, $hard));
});

it('holds more than 1024 connections on the poll driver and reaches every one with a broadcast', function () {
    // About 1,100 client objects with their HTTP upgrade state; the default
    // 128M is too tight with assertions on.
    ini_set('memory_limit', '1G');

    $server = ServerProcess::start('poll');
    $clients = [];
    $started = microtime(true);

    try {
        $deadline = new TimeoutCancellation(90.0, 'Watchdog: the connection-count test took longer than 90 seconds.');

        // Batches keep the accept backlog from overflowing.
        foreach (array_chunk(range(1, CLIENTS), BATCH) as $batch) {
            array_push($clients, ...await(array_map(fn () => async(function () use ($server, $deadline): WebsocketConnection {
                $client = connect("ws://127.0.0.1:{$server->port}/app/app-key", $deadline);

                $established = json_decode((string) $client->receive($deadline)?->buffer(), true);
                expect($established['event'] ?? null)->toBe('pusher:connection_established');

                $client->sendText(json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => 'crowd']]));

                $subscribed = json_decode((string) $client->receive($deadline)?->buffer(), true);
                expect($subscribed['event'] ?? null)->toBe('pusher_internal:subscription_succeeded');

                return $client;
            }), $batch), $deadline));
        }

        expect($clients)->toHaveCount(CLIENTS)
            ->and($server->running())->toBeTrue();

        $pusher = new Pusher('app-key', 'app-secret', 'app-id', [
            'host' => '127.0.0.1',
            'port' => $server->port,
            'scheme' => 'http',
            'useTLS' => false,
        ], new GuzzleClient(['timeout' => 10]));

        $pusher->trigger('crowd', 'announcement', ['n' => 42]);

        $received = await(array_map(fn (WebsocketConnection $client) => async(function () use ($client, $deadline): array {
            return json_decode((string) $client->receive($deadline)?->buffer(), true) ?? [];
        }), $clients), $deadline);

        expect($received)->toHaveCount(CLIENTS)
            ->each->toMatchArray(['event' => 'announcement', 'channel' => 'crowd']);

        await(array_map(fn (WebsocketConnection $client) => async(function () use ($client): void {
            $client->close();
        }), $clients), $deadline);

        expect(array_filter($clients, fn (WebsocketConnection $client) => ! $client->isClosed()))->toBeEmpty()
            ->and($server->running())->toBeTrue($server->log());

        $server->signal(SIGTERM);

        expect($server->wait(10.0))->toBe(0, $server->log())
            ->and(microtime(true) - $started)->toBeLessThan(90.0);
    } finally {
        foreach ($clients as $client) {
            $client->isClosed() || $client->close();
        }

        $server->stop();
    }
})->group('integration');

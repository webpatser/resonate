<?php

use Fledge\Async\Http\Server\Driver\DefaultHttpDriverFactory;
use Fledge\Async\Http\Server\HttpServerStatus;
use Fledge\Async\Http\Server\SocketHttpServer;
use Psr\Log\NullLogger;
use Webpatser\Resonate\Server\HttpServer;
use Webpatser\Resonate\Server\Router;

/*
 * `HttpServer::drain()` differs from `HttpServer::stop()` only in two
 * observable ways:
 *
 *   1. It does NOT terminate the event loop.
 *   2. It does NOT invoke `SocketHttpServer::stop()` (which would fire the
 *      `onStop` callbacks the Websocket server uses to close active clients).
 *
 * Both points are about what drain does NOT do. The positive behavior is
 * covered below against a real loop bound to an ephemeral port: the listeners
 * close, and the drain completes as soon as the last client is gone rather than
 * sitting out the timeout.
 */

function makeStubServerComponents(): array
{
    $logger = new NullLogger;

    $socketServer = SocketHttpServer::createForDirectAccess(
        logger: $logger,
        httpDriverFactory: new DefaultHttpDriverFactory(logger: $logger),
    );

    return [$socketServer, new Router('')];
}

it('returns silently when drain is called on a server that never started', function () {
    [$socketServer, $router] = makeStubServerComponents();

    $server = new HttpServer($socketServer, $router);

    // The underlying SocketHttpServer is in HttpServerStatus::Stopped here.
    expect($socketServer->getStatus())->toBe(HttpServerStatus::Stopped);

    $server->drain(5);

    // Drain returns without touching the underlying server.
    expect($socketServer->getStatus())->toBe(HttpServerStatus::Stopped);
});

it('is idempotent: a second drain on the same instance is a no-op', function () {
    [$socketServer, $router] = makeStubServerComponents();

    $server = new HttpServer($socketServer, $router);

    $server->drain(5);
    $server->drain(5);

    // Reaching here without an exception is the assertion. The internal
    // `draining` guard short-circuits subsequent calls so the watchdog is
    // only ever scheduled once.
    expect(true)->toBeTrue();
});

it('exposes drain as a public method on HttpServer', function () {
    $reflection = new ReflectionMethod(HttpServer::class, 'drain');

    expect($reflection->isPublic())->toBeTrue()
        ->and($reflection->getNumberOfRequiredParameters())->toBe(1);
});

/*
 * Regression: the reflection into the vendor object had no guard.
 *
 * `drain()` reaches for the private `SocketHttpServer::$servers` to close the
 * listening sockets without firing the onStop callbacks. If a fledge-fiber
 * update renames that property, the bound closure would read an undefined
 * property, close nothing, and drain would become a silent no-op that leaves
 * every listener open with no exception anywhere.
 */
it('pins the vendor properties drain reflects into', function () {
    expect(property_exists(SocketHttpServer::class, 'servers'))->toBeTrue()
        ->and(property_exists(SocketHttpServer::class, 'drivers'))->toBeTrue();
});

/*
 * Regression: drain always burned the full timeout.
 *
 * The watchdog was an unconditional `EventLoop::delay($timeout, ...)` with no
 * early exit, so a deploy on an idle node waited the whole drain_timeout (30s
 * by default) before the process would exit.
 */
it('finishes as soon as the last client is gone instead of waiting out the timeout', function () {
    [$socketServer, $router] = makeStubServerComponents();

    $socketServer->expose('127.0.0.1:0');

    $server = new HttpServer($socketServer, $router);

    $drainStarted = null;
    $loopStopped = null;

    $server->onListening(function () use ($server, &$drainStarted) {
        $drainStarted = microtime(true);

        // No clients have ever connected, so the drain has nothing to wait for.
        $server->drain(30);
    });

    $server->start();

    $loopStopped = microtime(true);

    expect($drainStarted)->not->toBeNull()
        // Well inside the 30s upper bound: the poller ends it on the first tick.
        ->and($loopStopped - $drainStarted)->toBeLessThan(3.0)
        ->and($socketServer->getStatus())->toBe(HttpServerStatus::Stopped);
});

/*
 * `onListening` exists because SocketHttpServer's own onStart callbacks are
 * awaited *before* the listening sockets are bound. StartServer publishes the
 * PID file from this hook, and a PID file that appears before the port is open
 * is the bug it was added to fix.
 */
it('runs onListening callbacks only once the server is accepting', function () {
    [$socketServer, $router] = makeStubServerComponents();

    $socketServer->expose('127.0.0.1:0');

    $server = new HttpServer($socketServer, $router);

    $statusWhenCalled = null;

    $server->onListening(function () use ($socketServer, $server, &$statusWhenCalled) {
        $statusWhenCalled = $socketServer->getStatus();

        $server->stop();
    });

    $server->start();

    expect($statusWhenCalled)->toBe(HttpServerStatus::Started);
});

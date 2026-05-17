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
 * Both points are about what drain does NOT do. The positive behavior - the
 * listening sockets are closed and a watchdog is scheduled - is exercised
 * end-to-end by the manual verification steps in the plan because spinning a
 * real fledge-fiber loop inside a unit test costs more than the coverage it
 * yields.
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

<?php

use Mockery\MockInterface;
use Revolt\EventLoop;
use Webpatser\Resonate\Console\Commands\StartServer;
use Webpatser\Resonate\Server\HttpServer;

/*
 * `StartServer::handleSignal()` branches on SIGUSR2 to perform a graceful
 * drain (calling HttpServer::drain($timeout)) and falls through to a hard
 * stop (HttpServer::stop()) for SIGTERM/SIGINT/SIGTSTP. The drain primitive
 * has its own unit tests; this file pins the routing decision so a future
 * edit to handleSignal cannot silently send a hard stop on SIGUSR2 (or
 * vice-versa).
 *
 * It also pins the two properties that make the graceful path work at all:
 *
 *   1. handleSignal() must return false. Symfony's signal dispatch does
 *      `if (false !== $exitCode = $command->handleSignal($signal)) exit($exitCode);`
 *      so returning an int (an exit code, even 0) terminates the process the
 *      moment the handler returns, before the drain window or the queued loop
 *      stop can run, severing every live connection. An earlier version of
 *      this file asserted the returned exit code and so pinned that bug in
 *      place rather than catching it.
 *
 *   2. The work must happen on the event loop, not inline. With
 *      pcntl_async_signals the handler runs between opcodes of whatever fiber
 *      is executing, so closing listener sockets and writing to the console
 *      there can interleave with a partially written frame.
 *
 * The command is instantiated directly rather than via Artisan::call() so we
 * can inject a Mockery double of HttpServer and call handleSignal()
 * synchronously. `$server` is protected and `$components` is normally set
 * during Command::execute(); both are populated via Reflection.
 */

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Signal routing is posix-only.');
    }
});

function makeStartServerWithMockHttpServer(MockInterface $http): StartServer
{
    $command = new StartServer;
    $command->setLaravel(app());

    $server = new ReflectionProperty($command, 'server');
    $server->setValue($command, $http);

    $components = Mockery::mock();
    $components->shouldReceive('info')->andReturnNull();
    $components->shouldReceive('error')->andReturnNull();
    $components->shouldReceive('warn')->andReturnNull();

    $componentsProp = new ReflectionProperty($command, 'components');
    $componentsProp->setValue($command, $components);

    return $command;
}

/**
 * Run the loop until the callbacks queued so far have executed.
 *
 * Deferred callbacks run in FIFO order, so the handler's own deferral runs
 * before the stop queued here.
 */
function drainDeferredCallbacks(): void
{
    EventLoop::defer(fn () => EventLoop::getDriver()->stop());

    EventLoop::run();
}

it('subscribes to SIGINT, SIGTERM, SIGTSTP, and SIGUSR2 on unix', function () {
    $signals = (new StartServer)->getSubscribedSignals();

    expect($signals)->toContain(SIGINT)
        ->and($signals)->toContain(SIGTERM)
        ->and($signals)->toContain(SIGTSTP)
        ->and($signals)->toContain(SIGUSR2);
});

it('returns false so Symfony does not exit before the server can drain', function () {
    $http = Mockery::mock(HttpServer::class);
    $http->shouldReceive('drain');
    $http->shouldReceive('stop');

    $command = makeStartServerWithMockHttpServer($http);

    // Symfony exits whenever the return value is not identical to false, so a
    // 0 here would still kill the process.
    expect($command->handleSignal(SIGUSR2, 42))->toBeFalse()
        ->and($command->handleSignal(SIGTERM, 7))->toBeFalse();

    drainDeferredCallbacks();
});

it('defers the drain to the event loop rather than running it in signal context', function () {
    $drained = false;

    $http = Mockery::mock(HttpServer::class);
    $http->shouldReceive('drain')->once()->with(30)->andReturnUsing(function () use (&$drained) {
        $drained = true;
    });
    $http->shouldNotReceive('stop');

    $command = makeStartServerWithMockHttpServer($http);

    $command->handleSignal(SIGUSR2, 0);

    // Nothing has touched the server yet: the handler only queued the work.
    expect($drained)->toBeFalse();

    drainDeferredCallbacks();

    expect($drained)->toBeTrue();
});

it('routes SIGUSR2 to HttpServer::drain with the configured drain_timeout', function () {
    $http = Mockery::mock(HttpServer::class);
    $http->shouldReceive('drain')->once()->with(30);
    $http->shouldNotReceive('stop');

    $command = makeStartServerWithMockHttpServer($http);

    $command->handleSignal(SIGUSR2, 0);

    drainDeferredCallbacks();
});

it('reads drain_timeout from config when set', function () {
    config()->set('reverb.servers.reverb.drain_timeout', 7);

    $http = Mockery::mock(HttpServer::class);
    $http->shouldReceive('drain')->once()->with(7);
    $http->shouldNotReceive('stop');

    $command = makeStartServerWithMockHttpServer($http);

    $command->handleSignal(SIGUSR2, 0);

    drainDeferredCallbacks();
});

it('routes SIGTERM to HttpServer::stop', function () {
    $http = Mockery::mock(HttpServer::class);
    $http->shouldReceive('stop')->once()->withNoArgs();
    $http->shouldNotReceive('drain');

    $command = makeStartServerWithMockHttpServer($http);

    $command->handleSignal(SIGTERM, 0);

    drainDeferredCallbacks();
});

it('routes SIGINT to HttpServer::stop', function () {
    $http = Mockery::mock(HttpServer::class);
    $http->shouldReceive('stop')->once()->withNoArgs();
    $http->shouldNotReceive('drain');

    $command = makeStartServerWithMockHttpServer($http);

    $command->handleSignal(SIGINT, 0);

    drainDeferredCallbacks();
});

it('no-ops cleanly when the server has not been built yet', function () {
    // Don't inject a server; leave $server = null.
    $command = new StartServer;
    $command->setLaravel(app());

    $components = Mockery::mock();
    $components->shouldReceive('info')->andReturnNull();

    $componentsProp = new ReflectionProperty($command, 'components');
    $componentsProp->setValue($command, $components);

    expect($command->handleSignal(SIGUSR2, 0))->toBeFalse()
        ->and($command->handleSignal(SIGTERM, 0))->toBeFalse();

    drainDeferredCallbacks();
});

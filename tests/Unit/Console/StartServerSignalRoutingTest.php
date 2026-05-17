<?php

use Mockery\MockInterface;
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

it('subscribes to SIGINT, SIGTERM, SIGTSTP, and SIGUSR2 on unix', function () {
    $signals = (new StartServer)->getSubscribedSignals();

    expect($signals)->toContain(SIGINT)
        ->and($signals)->toContain(SIGTERM)
        ->and($signals)->toContain(SIGTSTP)
        ->and($signals)->toContain(SIGUSR2);
});

it('routes SIGUSR2 to HttpServer::drain with the configured drain_timeout', function () {
    $http = Mockery::mock(HttpServer::class);
    $http->shouldReceive('drain')->once()->with(30);
    $http->shouldNotReceive('stop');

    $command = makeStartServerWithMockHttpServer($http);

    $result = $command->handleSignal(SIGUSR2, 0);

    expect($result)->toBe(0);
});

it('reads drain_timeout from config when set', function () {
    config()->set('reverb.servers.reverb.drain_timeout', 7);

    $http = Mockery::mock(HttpServer::class);
    $http->shouldReceive('drain')->once()->with(7);
    $http->shouldNotReceive('stop');

    $command = makeStartServerWithMockHttpServer($http);

    $command->handleSignal(SIGUSR2, 0);
});

it('routes SIGTERM to HttpServer::stop', function () {
    $http = Mockery::mock(HttpServer::class);
    $http->shouldReceive('stop')->once()->withNoArgs();
    $http->shouldNotReceive('drain');

    $command = makeStartServerWithMockHttpServer($http);

    $result = $command->handleSignal(SIGTERM, 0);

    expect($result)->toBe(0);
});

it('routes SIGINT to HttpServer::stop', function () {
    $http = Mockery::mock(HttpServer::class);
    $http->shouldReceive('stop')->once()->withNoArgs();
    $http->shouldNotReceive('drain');

    $command = makeStartServerWithMockHttpServer($http);

    $command->handleSignal(SIGINT, 0);
});

it('returns the previous exit code on every signal', function () {
    $http = Mockery::mock(HttpServer::class);
    $http->shouldReceive('drain');
    $http->shouldReceive('stop');

    $command = makeStartServerWithMockHttpServer($http);

    expect($command->handleSignal(SIGUSR2, 42))->toBe(42)
        ->and($command->handleSignal(SIGTERM, 7))->toBe(7);
});

it('no-ops cleanly when the server has not been built yet', function () {
    // Don't inject a server; leave $server = null.
    $command = new StartServer;
    $command->setLaravel(app());

    $components = Mockery::mock();
    $components->shouldReceive('info')->andReturnNull();

    $componentsProp = new ReflectionProperty($command, 'components');
    $componentsProp->setValue($command, $components);

    expect($command->handleSignal(SIGUSR2, 0))->toBe(0)
        ->and($command->handleSignal(SIGTERM, 0))->toBe(0);
});

<?php

use Illuminate\Support\Facades\Artisan;
use Webpatser\Resonate\Console\Commands\StartServer;

/*
 * Regression: `resonate:start` had no double-start guard.
 *
 * The listener always binds with SO_REUSEPORT (so that `resonate:reload` can
 * overlap two processes on purpose), which means a second `resonate:start`
 * binds the same port successfully instead of failing with EADDRINUSE. The two
 * processes then split accepts while keeping separate in-memory channel state,
 * so with scaling disabled roughly half of every broadcast silently misses its
 * subscribers. The command now refuses to start when the PID file names a live
 * process, unless --force is passed (which is what the reload spawner uses).
 *
 * These tests only exercise the refusal path: the success path would block on
 * a real event loop.
 */

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_kill')) {
        $this->markTestSkipped('The double-start guard relies on posix_kill.');
    }

    @mkdir(dirname(StartServer::pidFilePath()), 0755, true);

    removeStartServerFiles();
});

afterEach(function () {
    removeStartServerFiles();
});

function removeStartServerFiles(): void
{
    foreach ([StartServer::pidFilePath(), StartServer::runtimeFilePath()] as $path) {
        if (file_exists($path) && ! is_link($path)) {
            @unlink($path);
        }
    }
}

it('refuses to start when the PID file names a running process', function () {
    // Our own PID is by definition live.
    file_put_contents(StartServer::pidFilePath(), (string) getmypid());

    $exit = Artisan::call('resonate:start');

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('already running');
});

it('exposes a --force escape hatch for the reload spawner', function () {
    $definition = (new StartServer)->getDefinition();

    expect($definition->hasOption('force'))->toBeTrue();
});

it('leaves the existing PID file untouched when it refuses to start', function () {
    file_put_contents(StartServer::pidFilePath(), (string) getmypid());

    Artisan::call('resonate:start');

    // The refusing process must not adopt or clear the running server's PID
    // file; that is what made a failed start hide the live server.
    expect(file_exists(StartServer::pidFilePath()))->toBeTrue()
        ->and((int) file_get_contents(StartServer::pidFilePath()))->toBe(getmypid());
});

<?php

use Illuminate\Support\Facades\Artisan;
use Webpatser\Resonate\Console\Commands\ReloadServer;
use Webpatser\Resonate\Console\Commands\StartServer;

/*
 * `resonate:reload` orchestrates the zero-downtime swap: it reads the PID
 * written by `resonate:start`, optionally spawns a replacement, polls /up,
 * then sends SIGUSR2 to the old PID. The unit-of-test here is the orchestrator
 * itself; the StartServer side of the signal handling has its own tests.
 *
 * Tests skip cleanly on Windows because `posix_kill` and friends are not
 * available there and the command refuses to run.
 */

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_kill') || ! function_exists('pcntl_waitpid')) {
        $this->markTestSkipped('resonate:reload requires posix + pcntl extensions.');
    }

    $pidFile = StartServer::pidFilePath();

    if (file_exists($pidFile) && ! is_link($pidFile)) {
        @unlink($pidFile);
    }

    @mkdir(dirname($pidFile), 0755, true);

    ReloadServer::$spawner = null;
    ReloadServer::$probe = null;
});

afterEach(function () {
    ReloadServer::$spawner = null;
    ReloadServer::$probe = null;

    $pidFile = StartServer::pidFilePath();

    if (file_exists($pidFile) && ! is_link($pidFile)) {
        @unlink($pidFile);
    }

    // Reap any leftover children from spawned sleep processes so they do not
    // linger between tests as zombies.
    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
        // drain
    }
});

/**
 * Spawn a long-running `sleep` child we can target with a signal.
 *
 * SIGUSR2's default disposition is process termination; surviving sleep
 * proves the signal was NOT delivered, and a reaped sleep proves it was.
 */
function spawnSleepProcess(int $seconds = 30): int
{
    $pipes = [];

    $proc = proc_open(
        ['sleep', (string) $seconds],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ],
        $pipes,
    );

    if (! is_resource($proc)) {
        throw new RuntimeException('Failed to spawn helper sleep process.');
    }

    $status = proc_get_status($proc);

    return (int) $status['pid'];
}

function writeFakePidFile(int $pid): void
{
    file_put_contents(StartServer::pidFilePath(), (string) $pid);
}

/**
 * Test-side liveness check that reaps zombies before falling back to
 * `posix_kill(0)`. A `kill(pid, 0)` on a zombie returns success because the
 * process table entry still exists; only the parent reaping the child clears
 * it. The reload command itself does not need this because the real
 * `resonate:start` is not a child of `resonate:reload`.
 */
function processIsAlive(int $pid): bool
{
    $reaped = pcntl_waitpid($pid, $status, WNOHANG);

    if ($reaped === $pid) {
        return false;
    }

    if ($reaped === -1) {
        // Not our child (or already reaped); use kill(0).
        return @posix_kill($pid, 0);
    }

    return true;
}

function waitForExit(int $pid, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        if (! processIsAlive($pid)) {
            return true;
        }

        usleep(100_000);
    }

    return false;
}

it('returns failure when no PID file is present', function () {
    $exit = Artisan::call('resonate:reload', ['--drain' => true]);

    expect($exit)->not->toBe(0);
});

it('signals SIGUSR2 to the running PID in drain-only mode', function () {
    $childPid = spawnSleepProcess(30);
    writeFakePidFile($childPid);

    try {
        $exit = Artisan::call('resonate:reload', [
            '--drain' => true,
            '--timeout' => 3,
        ]);

        expect($exit)->toBe(0)
            ->and(waitForExit($childPid, 2))->toBeTrue();
    } finally {
        if (processIsAlive($childPid)) {
            posix_kill($childPid, SIGKILL);
            pcntl_waitpid($childPid, $status);
        }
    }
});

it('returns failure when the spawner reports a failure to spawn', function () {
    $childPid = spawnSleepProcess(30);
    writeFakePidFile($childPid);

    ReloadServer::$spawner = fn () => null;

    try {
        $exit = Artisan::call('resonate:reload', [
            '--health-timeout' => 1,
            '--timeout' => 1,
        ]);

        expect($exit)->not->toBe(0)
            // The old server must NOT have been signalled when the spawn failed.
            ->and(processIsAlive($childPid))->toBeTrue();
    } finally {
        posix_kill($childPid, SIGKILL);
        pcntl_waitpid($childPid, $status);
    }
});

it('fails when the new server never answers /up within the health timeout', function () {
    $oldPid = spawnSleepProcess(30);
    $newPid = spawnSleepProcess(30);

    writeFakePidFile($oldPid);

    ReloadServer::$spawner = fn () => $newPid;
    ReloadServer::$probe = fn () => false; // never healthy

    try {
        $exit = Artisan::call('resonate:reload', [
            '--health-timeout' => 1,
            '--timeout' => 1,
        ]);

        expect($exit)->not->toBe(0)
            // The orchestrator SIGTERMs the new pid when it never goes healthy.
            ->and(waitForExit($newPid, 2))->toBeTrue()
            // The orchestrator must NOT have signalled the old server.
            ->and(processIsAlive($oldPid))->toBeTrue();
    } finally {
        foreach ([$newPid, $oldPid] as $pid) {
            if (processIsAlive($pid)) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
    }
});

it('completes the full reload when /up responds and the old PID drains', function () {
    $oldPid = spawnSleepProcess(30);
    $newPid = spawnSleepProcess(30);

    writeFakePidFile($oldPid);

    ReloadServer::$spawner = fn () => $newPid;
    ReloadServer::$probe = fn () => true; // immediately healthy

    try {
        $exit = Artisan::call('resonate:reload', [
            '--health-timeout' => 5,
            '--timeout' => 3,
        ]);

        expect($exit)->toBe(0)
            ->and(waitForExit($oldPid, 2))->toBeTrue()
            // The new server keeps running once the old one is drained.
            ->and(processIsAlive($newPid))->toBeTrue();
    } finally {
        foreach ([$newPid, $oldPid] as $pid) {
            if (processIsAlive($pid)) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
    }
});

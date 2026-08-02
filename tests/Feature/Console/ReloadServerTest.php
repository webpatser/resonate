<?php

use Illuminate\Support\Facades\Artisan;
use Webpatser\Resonate\Console\Commands\ReloadServer;
use Webpatser\Resonate\Console\Commands\StartServer;

/*
 * `resonate:reload` orchestrates the zero-downtime swap: it reads the PID
 * written by `resonate:start`, optionally spawns a replacement, polls /up until
 * the spawned PID itself answers, then sends SIGUSR2 to the old PID. The
 * unit-of-test here is the orchestrator itself; the StartServer side of the
 * signal handling has its own tests.
 *
 * Tests skip cleanly on Windows because `posix_kill` and friends are not
 * available there and the command refuses to run.
 */

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_kill') || ! function_exists('pcntl_waitpid')) {
        $this->markTestSkipped('resonate:reload requires posix + pcntl extensions.');
    }

    cleanupServerFiles();

    @mkdir(dirname(StartServer::pidFilePath()), 0755, true);

    ReloadServer::$spawner = null;
    ReloadServer::$probe = null;
});

afterEach(function () {
    ReloadServer::$spawner = null;
    ReloadServer::$probe = null;

    cleanupServerFiles();

    // Reap any leftover children so they do not linger between tests as zombies.
    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
        // drain
    }
});

function cleanupServerFiles(): void
{
    foreach ([StartServer::pidFilePath(), StartServer::runtimeFilePath()] as $path) {
        if (file_exists($path) && ! is_link($path)) {
            @unlink($path);
        }
    }
}

/**
 * Spawn a long-running helper process we can target with a signal.
 *
 * Deliberately a *grandchild*: `sh` backgrounds the sleep, prints its PID and
 * exits, so the sleep is reparented to init and reaped by it. A direct child
 * would linger as a zombie after being signalled, and `posix_kill($pid, 0)`
 * succeeds on a zombie, which would make every liveness check in the command
 * report a dead server as alive. The real `resonate:start` is never a child of
 * `resonate:reload`, so this shape matches production.
 *
 * With `$trapSignals` the process ignores SIGUSR2 and SIGTERM (the disposition
 * survives the exec into `sleep`), which is how a wedged old server behaves.
 */
function spawnSleepProcess(int $seconds = 30, bool $trapSignals = false): int
{
    // The sleep gets its own stdio: leaving it attached to the pipe below would
    // hold that pipe open for its full lifetime, so reading the PID would block
    // until the process we are trying to observe had already exited.
    $script = $trapSignals ? "trap '' USR2 TERM; " : '';
    $script .= "sleep {$seconds} >/dev/null 2>&1 </dev/null & echo \$!";

    $pipes = [];

    $proc = proc_open(
        ['sh', '-c', $script],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'a'],
        ],
        $pipes,
    );

    if (! is_resource($proc)) {
        throw new RuntimeException('Failed to spawn helper sleep process.');
    }

    $pid = (int) trim((string) stream_get_contents($pipes[1]));

    fclose($pipes[1]);
    proc_close($proc);

    if ($pid <= 0) {
        throw new RuntimeException('Failed to read the helper sleep PID.');
    }

    return $pid;
}

function writeFakePidFile(int $pid): void
{
    file_put_contents(StartServer::pidFilePath(), (string) $pid);
}

function writeFakeRuntimeFile(int $pid, string $host = '127.0.0.1', int $port = 9123, string $path = ''): void
{
    file_put_contents(StartServer::runtimeFilePath(), json_encode([
        'pid' => $pid,
        'host' => $host,
        'port' => $port,
        'path' => $path,
    ]));
}

function processIsAlive(int $pid): bool
{
    return @posix_kill($pid, 0);
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

function killHelpers(int ...$pids): void
{
    foreach ($pids as $pid) {
        if (processIsAlive($pid)) {
            @posix_kill($pid, SIGKILL);
        }
    }
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
            ->and(processIsAlive($childPid))->toBeFalse();
    } finally {
        killHelpers($childPid);
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
        killHelpers($childPid);
    }
});

it('fails when the new server never answers /up within the health timeout', function () {
    $oldPid = spawnSleepProcess(30);
    $newPid = spawnSleepProcess(30);

    writeFakePidFile($oldPid);

    ReloadServer::$spawner = fn () => $newPid;
    ReloadServer::$probe = fn () => null; // never healthy

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
        killHelpers($newPid, $oldPid);
    }
});

it('completes the full reload when /up responds and the old PID drains', function () {
    $oldPid = spawnSleepProcess(30);
    $newPid = spawnSleepProcess(30);

    writeFakePidFile($oldPid);

    ReloadServer::$spawner = fn () => $newPid;
    ReloadServer::$probe = fn () => $newPid; // the replacement itself answers

    try {
        $exit = Artisan::call('resonate:reload', [
            '--health-timeout' => 5,
            '--timeout' => 3,
        ]);

        expect($exit)->toBe(0)
            ->and(processIsAlive($oldPid))->toBeFalse()
            // The new server keeps running once the old one is drained.
            ->and(processIsAlive($newPid))->toBeTrue();
    } finally {
        killHelpers($newPid, $oldPid);
    }
});

/*
 * Regression: identity in the health probe.
 *
 * Both servers hold the port at once (SO_REUSEPORT), so a 200 from "the port"
 * proves nothing. If the replacement dies during boot and the old server keeps
 * answering /up, the old version drained the only remaining server and left the
 * node with nothing listening.
 */
it('refuses to drain the old server when /up is answered by a different PID', function () {
    $oldPid = spawnSleepProcess(30);
    $newPid = spawnSleepProcess(30);

    writeFakePidFile($oldPid);

    ReloadServer::$spawner = fn () => $newPid;
    // The OLD server answers the probe, exactly what SO_REUSEPORT allows.
    ReloadServer::$probe = fn () => $oldPid;

    try {
        $exit = Artisan::call('resonate:reload', [
            '--health-timeout' => 1,
            '--timeout' => 1,
        ]);

        expect($exit)->not->toBe(0)
            ->and(processIsAlive($oldPid))->toBeTrue();
    } finally {
        killHelpers($newPid, $oldPid);
    }
});

it('fails fast when the spawned replacement dies during startup', function () {
    $oldPid = spawnSleepProcess(30);
    $newPid = spawnSleepProcess(30);

    // The replacement is already gone by the time the probe is polled.
    @posix_kill($newPid, SIGKILL);
    waitForExit($newPid, 2);

    writeFakePidFile($oldPid);

    ReloadServer::$spawner = fn () => $newPid;
    // Something on the port is healthy, but it is not the process we spawned.
    ReloadServer::$probe = fn () => $oldPid;

    try {
        $started = microtime(true);

        $exit = Artisan::call('resonate:reload', [
            '--health-timeout' => 10,
            '--timeout' => 1,
        ]);

        expect($exit)->not->toBe(0)
            // The liveness check short-circuits instead of burning the timeout.
            ->and(microtime(true) - $started)->toBeLessThan(5.0)
            ->and(processIsAlive($oldPid))->toBeTrue();
    } finally {
        killHelpers($oldPid);
    }
});

/*
 * Regression: the replacement must inherit the running server's CLI overrides.
 */
it('passes the recorded host, port and path to the spawner and the probe', function () {
    $oldPid = spawnSleepProcess(30);
    $newPid = spawnSleepProcess(30);

    writeFakePidFile($oldPid);
    writeFakeRuntimeFile($oldPid, host: '127.0.0.1', port: 9911, path: '/ws');

    $spawnedWith = null;
    $probedWith = null;

    ReloadServer::$spawner = function (array $options) use (&$spawnedWith, $newPid) {
        $spawnedWith = $options;

        return $newPid;
    };

    ReloadServer::$probe = function (string $host, int $port, string $path) use (&$probedWith, $newPid) {
        $probedWith = [$host, $port, $path];

        return $newPid;
    };

    try {
        $exit = Artisan::call('resonate:reload', [
            '--health-timeout' => 5,
            '--timeout' => 3,
        ]);

        expect($exit)->toBe(0)
            ->and($spawnedWith)->toBe(['host' => '127.0.0.1', 'port' => 9911, 'path' => '/ws'])
            ->and($probedWith)->toBe(['127.0.0.1', 9911, '/ws']);
    } finally {
        killHelpers($newPid, $oldPid);
    }
});

it('ignores runtime metadata that describes a different process', function () {
    $oldPid = spawnSleepProcess(30);
    $newPid = spawnSleepProcess(30);

    writeFakePidFile($oldPid);
    // Left behind by an earlier server; must not be used for this PID.
    writeFakeRuntimeFile($oldPid + 12345, host: '10.9.9.9', port: 9911);

    $spawnedWith = null;

    ReloadServer::$spawner = function (array $options) use (&$spawnedWith, $newPid) {
        $spawnedWith = $options;

        return $newPid;
    };

    ReloadServer::$probe = fn () => $newPid;

    try {
        Artisan::call('resonate:reload', ['--health-timeout' => 5, '--timeout' => 3]);

        $config = config('reverb.servers.reverb');

        expect($spawnedWith['host'])->toBe((string) $config['host'])
            ->and($spawnedWith['port'])->toBe((int) $config['port']);
    } finally {
        killHelpers($newPid, $oldPid);
    }
});

/*
 * Regression: SIGTERM without confirmation used to report success.
 */
it('returns failure when the old server survives both SIGUSR2 and SIGTERM', function () {
    $stubborn = spawnSleepProcess(30, trapSignals: true);

    writeFakePidFile($stubborn);

    try {
        $exit = Artisan::call('resonate:reload', [
            '--drain' => true,
            '--timeout' => 0,
            '--term-timeout' => 1,
        ]);

        expect($exit)->not->toBe(0)
            ->and(processIsAlive($stubborn))->toBeTrue();
    } finally {
        killHelpers($stubborn);
    }
});

it('parses the PID out of a health check body', function () {
    $probe = new ReflectionMethod(ReloadServer::class, 'pidFromHealthBody');

    $command = new ReloadServer;

    $ok = "HTTP/1.0 200 OK\r\nContent-Type: application/json\r\n\r\n".json_encode(['health' => 'OK', 'pid' => 4242]);

    expect($probe->invoke($command, $ok))->toBe(4242)
        // A server predating the identity field cannot be matched.
        ->and($probe->invoke($command, "HTTP/1.0 200 OK\r\n\r\n".json_encode(['health' => 'OK'])))->toBeNull()
        ->and($probe->invoke($command, 'garbage'))->toBeNull();
});

<?php

use Webpatser\Resonate\Console\Commands\StartServer;

/*
 * Pin the contract of the three PID-file helpers in StartServer:
 *
 *   - readPid(): public static, returns the PID stored in storage/resonate.pid
 *     if a process by that PID is still alive, null otherwise. Refuses to
 *     dereference a symlink at the path (TOCTOU guard) and unlinks stale
 *     entries.
 *
 *   - writePidFile(): protected, writes our own PID atomically via
 *     tmpfile + rename. Refuses to overwrite a symlink at the path.
 *
 *   - removePidFile(): protected, only unlinks when the file still points at
 *     our own PID. The "only mine" rule is the regression guard for the
 *     reload swap: a draining old server must not delete the new server's
 *     PID file after the new server has rewritten it.
 *
 * Skip on Windows because StartServer::readPid() requires posix_kill.
 */

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_kill')) {
        $this->markTestSkipped('PID file handling is posix-only.');
    }

    @mkdir(dirname(StartServer::pidFilePath()), 0755, true);
    cleanupPidFile();
});

afterEach(function () {
    cleanupPidFile();
});

function cleanupPidFile(): void
{
    $path = StartServer::pidFilePath();

    if (is_link($path)) {
        @unlink($path);

        return;
    }

    if (file_exists($path)) {
        @unlink($path);
    }

    @unlink($path.'.'.getmypid().'.tmp');
}

function invokePidMethod(string $method, ?StartServer $command = null): mixed
{
    $command ??= new StartServer;

    return (new ReflectionMethod($command, $method))->invoke($command);
}

it('readPid returns null when the pid file is missing', function () {
    expect(StartServer::readPid())->toBeNull();
});

it('readPid returns null and unlinks the file when the pid is stale', function () {
    // Fork a child, kill it, wait for it to exit, then use its (now-stale)
    // PID. Using a hard-coded "high" PID would be flaky because the kernel
    // could legitimately be reusing it.
    $child = pcntl_fork();
    if ($child === 0) {
        usleep(50_000);
        exit(0);
    }

    posix_kill($child, SIGKILL);
    pcntl_waitpid($child, $status);

    file_put_contents(StartServer::pidFilePath(), (string) $child);

    expect(StartServer::readPid())->toBeNull()
        ->and(file_exists(StartServer::pidFilePath()))->toBeFalse();
});

it('readPid returns the live pid when the file points at a running process', function () {
    file_put_contents(StartServer::pidFilePath(), (string) getmypid());

    expect(StartServer::readPid())->toBe(getmypid());
});

it('readPid returns null when the path is a symlink', function () {
    symlink('/dev/null', StartServer::pidFilePath());

    expect(StartServer::readPid())->toBeNull()
        ->and(is_link(StartServer::pidFilePath()))->toBeTrue();
});

it('writePidFile writes our own PID atomically', function () {
    invokePidMethod('writePidFile');

    expect(file_exists(StartServer::pidFilePath()))->toBeTrue()
        ->and((int) file_get_contents(StartServer::pidFilePath()))->toBe(getmypid())
        ->and(file_exists(StartServer::pidFilePath().'.'.getmypid().'.tmp'))->toBeFalse();
});

it('writePidFile refuses to start when the path is a symlink', function () {
    symlink('/dev/null', StartServer::pidFilePath());

    expect(fn () => invokePidMethod('writePidFile'))
        ->toThrow(RuntimeException::class, 'is a symlink');
});

it('removePidFile unlinks the file when it points at our own PID', function () {
    file_put_contents(StartServer::pidFilePath(), (string) getmypid());

    invokePidMethod('removePidFile');

    expect(file_exists(StartServer::pidFilePath()))->toBeFalse();
});

it('removePidFile leaves the file alone when it points at a different PID', function () {
    // This is the regression guard for the reload swap: a draining old
    // server must not delete the new server's PID file after the new server
    // has overwritten it.
    $otherPid = getmypid() + 1;
    file_put_contents(StartServer::pidFilePath(), (string) $otherPid);

    invokePidMethod('removePidFile');

    expect(file_exists(StartServer::pidFilePath()))->toBeTrue()
        ->and((int) file_get_contents(StartServer::pidFilePath()))->toBe($otherPid);
});

it('removePidFile is a no-op when the file is missing', function () {
    invokePidMethod('removePidFile');

    expect(file_exists(StartServer::pidFilePath()))->toBeFalse();
});

it('removePidFile leaves a symlink alone', function () {
    symlink('/dev/null', StartServer::pidFilePath());

    invokePidMethod('removePidFile');

    expect(is_link(StartServer::pidFilePath()))->toBeTrue();
});

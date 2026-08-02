<?php

use Webpatser\Resonate\Console\Commands\StartServer;

/*
 * The runtime metadata file records the address the server actually bound to.
 *
 * `resonate:reload` needs it because a server started with --host, --port or
 * --path is serving an address that appears nowhere in the config; without it
 * the replacement bound the config port while the probe polled a third one.
 *
 * It follows the same rules as the PID file: atomic tmp + rename, refuse a
 * symlink at the path, and only remove it when it still describes us (a
 * draining old server must not delete the new server's metadata).
 */

beforeEach(function () {
    @mkdir(dirname(StartServer::runtimeFilePath()), 0755, true);

    cleanupRuntimeFile();
});

afterEach(function () {
    cleanupRuntimeFile();
});

function cleanupRuntimeFile(): void
{
    $path = StartServer::runtimeFilePath();

    if (is_link($path) || file_exists($path)) {
        @unlink($path);
    }

    @unlink($path.'.'.getmypid().'.tmp');
}

function invokeRuntimeMethod(string $method, array $arguments = []): mixed
{
    return (new ReflectionMethod(StartServer::class, $method))->invokeArgs(new StartServer, $arguments);
}

it('sits beside the PID file', function () {
    expect(dirname(StartServer::runtimeFilePath()))->toBe(dirname(StartServer::pidFilePath()));
});

it('readRuntime returns null when the file is missing', function () {
    expect(StartServer::readRuntime())->toBeNull();
});

it('writes the effective host, port and path atomically', function () {
    invokeRuntimeMethod('writeRuntimeFile', ['127.0.0.1', 9001, '/ws']);

    expect(StartServer::readRuntime())->toBe([
        'pid' => getmypid(),
        'host' => '127.0.0.1',
        'port' => 9001,
        'path' => '/ws',
    ])->and(file_exists(StartServer::runtimeFilePath().'.'.getmypid().'.tmp'))->toBeFalse();
});

it('readRuntime returns null when the metadata describes a different process', function () {
    invokeRuntimeMethod('writeRuntimeFile', ['127.0.0.1', 9001, '']);

    expect(StartServer::readRuntime(getmypid()))->not->toBeNull()
        ->and(StartServer::readRuntime(getmypid() + 1))->toBeNull();
});

it('readRuntime returns null for malformed or partial metadata', function () {
    file_put_contents(StartServer::runtimeFilePath(), 'not json');
    expect(StartServer::readRuntime())->toBeNull();

    file_put_contents(StartServer::runtimeFilePath(), json_encode(['pid' => 1]));
    expect(StartServer::readRuntime())->toBeNull();
});

it('readRuntime refuses to follow a symlink at the path', function () {
    symlink('/dev/null', StartServer::runtimeFilePath());

    expect(StartServer::readRuntime())->toBeNull()
        ->and(is_link(StartServer::runtimeFilePath()))->toBeTrue();
});

it('refuses to write over a symlink without tearing the server down', function () {
    symlink('/dev/null', StartServer::runtimeFilePath());

    // Unlike the PID file, a failure here is logged rather than thrown: the
    // server is already accepting connections by this point.
    invokeRuntimeMethod('writeRuntimeFile', ['127.0.0.1', 9001, '']);

    expect(is_link(StartServer::runtimeFilePath()))->toBeTrue();
});

it('removes the metadata only when it still describes us', function () {
    invokeRuntimeMethod('writeRuntimeFile', ['127.0.0.1', 9001, '']);
    invokeRuntimeMethod('removeRuntimeFile');

    expect(file_exists(StartServer::runtimeFilePath()))->toBeFalse();

    file_put_contents(StartServer::runtimeFilePath(), json_encode([
        'pid' => getmypid() + 1,
        'host' => '127.0.0.1',
        'port' => 9001,
        'path' => '',
    ]));

    invokeRuntimeMethod('removeRuntimeFile');

    expect(file_exists(StartServer::runtimeFilePath()))->toBeTrue();
});

/*
 * Regression: the PID file used to be written at boot, before the server could
 * possibly answer a health probe. A replacement that died during startup left
 * the file pointing at a dead PID while the still-serving old server became
 * undiscoverable, and the next reload unlinked it and errored with "no running
 * server found". Publication is now deferred to the onListening hook, which
 * fires after the listening sockets are bound.
 */
it('publishes the PID file and the metadata together', function () {
    invokeRuntimeMethod('publishRuntimeState', ['127.0.0.1', 9001, '/ws']);

    try {
        expect((int) file_get_contents(StartServer::pidFilePath()))->toBe(getmypid())
            ->and(StartServer::readRuntime(getmypid()))->not->toBeNull();
    } finally {
        @unlink(StartServer::pidFilePath());
    }
});

it('registers publication as an onListening callback instead of running it at boot', function () {
    $source = file_get_contents((string) (new ReflectionClass(StartServer::class))->getFileName());

    // The single writePidFile() call site is publishRuntimeState(), and that is
    // reached only through the hook that fires once the listeners are bound.
    expect(substr_count($source, '$this->writePidFile()'))->toBe(1)
        ->and($source)->toContain('onListening(fn () => $this->publishRuntimeState(');
});

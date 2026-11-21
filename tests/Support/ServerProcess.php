<?php

namespace Webpatser\Resonate\Tests\Support;

use RuntimeException;

/**
 * A `resonate:start` process for the tests that need a real server.
 *
 * The child runs tests/Fixtures/start-server.php with the parent's ini
 * assertions, its own storage directory and the event loop driver given here.
 * Its output goes to a log file, not a pipe, so the test never watches a
 * stream of the child and closing the process handle closes nothing the loop
 * knows about. {@see stop()} always kills and reaps the child, so a failing
 * test does not leave a server behind.
 */
final class ServerProcess
{
    /** @var resource|null */
    private $process;

    private function __construct(
        public readonly int $pid,
        public readonly int $port,
        public readonly string $storage,
        $process,
    ) {
        $this->process = $process;
    }

    /**
     * Start the server and wait until it publishes its PID file, which it
     * does once the listener is bound.
     *
     * @param  array<string, string>  $env
     */
    public static function start(string $driver, array $env = [], float $timeout = 20.0): self
    {
        $storage = sys_get_temp_dir().'/resonate-server-'.getmypid().'-'.bin2hex(random_bytes(4));

        if (! mkdir($storage, 0700, true)) {
            throw new RuntimeException("Cannot create {$storage}.");
        }

        $port = self::freePort();

        $command = [
            PHP_BINARY,
            '-d', 'zend.assertions='.ini_get('zend.assertions'),
            '-d', 'assert.exception='.ini_get('assert.exception'),
            '-d', 'memory_limit=-1',
            dirname(__DIR__).'/Fixtures/start-server.php',
        ];

        $environment = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'FLEDGE_EVENT_LOOP' => $driver,
            'RESONATE_TEST_STORAGE' => $storage,
            'REVERB_SERVER_HOST' => '127.0.0.1',
            'REVERB_SERVER_PORT' => (string) $port,
            'REVERB_APP_KEY' => 'app-key',
            'REVERB_APP_SECRET' => 'app-secret',
            'REVERB_APP_ID' => 'app-id',
        ], $env);

        $pipes = [];

        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', "{$storage}/server.log", 'a'],
            2 => ['file', "{$storage}/server.log", 'a'],
        ], $pipes, dirname(__DIR__, 2), $environment);

        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start the server process.');
        }

        $server = new self((int) proc_get_status($process)['pid'], $port, $storage, $process);

        $deadline = microtime(true) + $timeout;

        while (! is_file("{$storage}/resonate.pid")) {
            if (! $server->running() || microtime(true) > $deadline) {
                $log = $server->log();
                $server->stop();

                throw new RuntimeException("The server did not start:\n{$log}");
            }

            usleep(20_000);
        }

        return $server;
    }

    /**
     * Whether the child is still running.
     */
    public function running(): bool
    {
        return is_resource($this->process) && proc_get_status($this->process)['running'];
    }

    /**
     * Send a signal to the child.
     */
    public function signal(int $signal): void
    {
        posix_kill($this->pid, $signal);
    }

    /**
     * Wait for the child to exit and return its exit code, or null on timeout.
     */
    public function wait(float $timeout): ?int
    {
        $deadline = microtime(true) + $timeout;

        while (is_resource($this->process)) {
            $status = proc_get_status($this->process);

            if (! $status['running']) {
                return $status['exitcode'];
            }

            if (microtime(true) > $deadline) {
                return null;
            }

            usleep(10_000);
        }

        return null;
    }

    /**
     * The child's console output so far.
     */
    public function log(): string
    {
        return (string) @file_get_contents("{$this->storage}/server.log");
    }

    /**
     * Kill the child if it still runs, reap it and remove its storage.
     */
    public function stop(): void
    {
        if (is_resource($this->process)) {
            if (proc_get_status($this->process)['running']) {
                posix_kill($this->pid, SIGKILL);
            }

            proc_close($this->process);
        }

        $this->process = null;

        foreach (glob("{$this->storage}/*") ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->storage);
    }

    /**
     * Ask the kernel for a free loopback port.
     */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            throw new RuntimeException("Cannot find a free port: {$errstr}");
        }

        $name = (string) stream_socket_get_name($socket, false);

        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}

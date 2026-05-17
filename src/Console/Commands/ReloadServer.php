<?php

namespace Webpatser\Resonate\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Webpatser\Resonate\Server\Factory;

/**
 * Zero-downtime reload of the Resonate server.
 *
 * Mirrors the `nginx -s reload` flow: spawn a replacement bound to the same
 * port via SO_REUSEPORT (already enabled in {@see Factory}),
 * wait for it to answer `/up`, then signal SIGUSR2 to the old PID so it stops
 * accepting and lets in-flight WebSocket connections finish.
 *
 * With `--drain` the spawn step is skipped and only the SIGUSR2 is sent, which
 * is the right shape when an external supervisor (systemd, k8s, Supervisor)
 * already brings up the replacement process.
 */
#[AsCommand(name: 'resonate:reload')]
class ReloadServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resonate:reload
                {--drain : Only signal the running server to drain; do not spawn a replacement}
                {--timeout=30 : Seconds to wait for the old server to exit after drain}
                {--health-timeout=10 : Seconds to wait for the new server to answer /up}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reload the Resonate server with zero downtime';

    /**
     * Spawner callable, swappable in tests.
     *
     * Returns the PID of the spawned `resonate:start` process, or null on
     * failure. The default implementation uses `proc_open` and lets the
     * child be reparented to init when this command exits.
     *
     * @var callable():(?int)|null
     */
    public static $spawner = null;

    /**
     * Health-probe callable, swappable in tests.
     *
     * Receives the host and port to probe and returns true when the new
     * server is healthy. Defaults to a one-shot HTTP/1.0 GET against
     * `/up` (see {@see static::probe()}).
     *
     * @var callable(string, int):bool|null
     */
    public static $probe = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (windows_os() || ! function_exists('posix_kill')) {
            $this->components->error('resonate:reload requires posix extensions and is not supported on Windows.');

            return self::FAILURE;
        }

        $oldPid = StartServer::readPid();

        if ($oldPid === null) {
            $this->components->error('No running Resonate server found (storage/resonate.pid missing or stale).');

            return self::FAILURE;
        }

        $config = $this->laravel['config']['reverb.servers.reverb'];
        $host = $config['host'] ?? '0.0.0.0';
        $port = (int) ($config['port'] ?? 8080);
        $drainTimeout = max(0, (int) $this->option('timeout'));
        $healthTimeout = max(1, (int) $this->option('health-timeout'));

        if ($this->option('drain')) {
            $this->components->info("Draining Resonate server (PID: {$oldPid}).");

            return $this->waitForExit($oldPid, $drainTimeout);
        }

        $this->components->info("Spawning replacement server (current PID: {$oldPid}).");

        $newPid = $this->spawn();

        if ($newPid === null) {
            $this->components->error('Failed to spawn replacement server.');

            return self::FAILURE;
        }

        $this->components->info("New server PID: {$newPid}. Waiting for /up to answer 200.");

        if (! $this->waitForHealth($host, $port, $healthTimeout)) {
            $this->components->error('New server did not become healthy in time; terminating it.');
            @posix_kill($newPid, SIGTERM);

            return self::FAILURE;
        }

        $this->components->info("New server healthy; draining old server (PID: {$oldPid}).");

        return $this->waitForExit($oldPid, $drainTimeout);
    }

    /**
     * Signal the old server to drain and wait for it to exit.
     *
     * The drain timeout on the server side acts as a hard upper bound; we
     * wait a few extra seconds here so the watchdog has time to fire and
     * the process to actually exit before we escalate to SIGTERM.
     */
    protected function waitForExit(int $pid, int $timeout): int
    {
        if (! @posix_kill($pid, SIGUSR2)) {
            $this->components->error("Failed to signal PID {$pid} (SIGUSR2).");

            return self::FAILURE;
        }

        $deadline = microtime(true) + $timeout + 5;

        while (microtime(true) < $deadline) {
            if (! @posix_kill($pid, 0)) {
                $this->components->info("Old server (PID: {$pid}) exited cleanly.");

                return self::SUCCESS;
            }

            usleep(200_000);
        }

        $this->components->warn("Old server (PID: {$pid}) did not exit within the drain window; sending SIGTERM.");
        @posix_kill($pid, SIGTERM);

        return self::SUCCESS;
    }

    /**
     * Spawn a detached `resonate:start` child process.
     */
    protected function spawn(): ?int
    {
        if (is_callable(static::$spawner)) {
            return (static::$spawner)();
        }

        $artisan = base_path('artisan');

        if (! is_file($artisan)) {
            return null;
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ];

        $pipes = [];

        $process = @proc_open(
            [PHP_BINARY, $artisan, 'resonate:start'],
            $descriptors,
            $pipes,
            base_path(),
        );

        if (! is_resource($process)) {
            return null;
        }

        $status = proc_get_status($process);

        return $status['pid'] ?? null;
    }

    /**
     * Poll the server's `/up` endpoint until it answers 200 or we time out.
     */
    protected function waitForHealth(string $host, int $port, int $timeout): bool
    {
        $checkHost = $host === '0.0.0.0' ? '127.0.0.1' : $host;
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            if ($this->probe($checkHost, $port)) {
                return true;
            }

            usleep(250_000);
        }

        return false;
    }

    /**
     * Issue an HTTP/1.0 GET /up and return true when the response is a 200.
     */
    protected function probe(string $host, int $port): bool
    {
        if (is_callable(static::$probe)) {
            return (bool) (static::$probe)($host, $port);
        }

        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 1);

        if (! is_resource($socket)) {
            return false;
        }

        stream_set_timeout($socket, 1);

        @fwrite($socket, "GET /up HTTP/1.0\r\nHost: {$host}:{$port}\r\nConnection: close\r\n\r\n");
        $response = @stream_get_contents($socket, 4096);
        @fclose($socket);

        return is_string($response)
            && (str_starts_with($response, 'HTTP/1.0 200') || str_starts_with($response, 'HTTP/1.1 200'));
    }
}

<?php

namespace Webpatser\Resonate\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
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
 * Because SO_REUSEPORT means both processes hold the port at once, the probe
 * cannot trust a bare 200: the kernel may well have routed it to the old
 * server. `/up` therefore reports the PID of whoever answered and the probe
 * only counts a response from the PID we just spawned.
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
                {--term-timeout=5 : Seconds to wait for the old server to exit after SIGTERM}
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
     * Receives the effective server options (`host`, `port`, `path`) the
     * running server was started with, and returns the PID of the spawned
     * `resonate:start` process, or null on failure. The default implementation
     * uses `proc_open` and lets the child be reparented to init when this
     * command exits.
     *
     * @var callable(array{host: string, port: int, path: string}):(?int)|null
     */
    public static $spawner = null;

    /**
     * Health-probe callable, swappable in tests.
     *
     * Receives the host, port and path prefix to probe and returns the PID the
     * server reported at `/up`, or null when the probe failed. Defaults to a
     * one-shot HTTP/1.0 GET (see {@see static::probe()}).
     *
     * @var callable(string, int, string):(?int)|null
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

        ['host' => $host, 'port' => $port, 'path' => $path] = $this->serverOptions($oldPid);

        $drainTimeout = max(0, (int) $this->option('timeout'));
        $termTimeout = max(0, (int) $this->option('term-timeout'));
        $healthTimeout = max(1, (int) $this->option('health-timeout'));

        if ($this->option('drain')) {
            $this->components->info("Draining Resonate server (PID: {$oldPid}).");

            return $this->waitForExit($oldPid, $drainTimeout, $termTimeout);
        }

        $this->components->info("Spawning replacement server (current PID: {$oldPid}).");

        $newPid = $this->spawn(['host' => $host, 'port' => $port, 'path' => $path]);

        if ($newPid === null) {
            $this->components->error('Failed to spawn replacement server.');

            return self::FAILURE;
        }

        $this->components->info("New server PID: {$newPid}. Waiting for {$path}/up to answer 200 from that PID.");

        if (! $this->waitForHealth($host, $port, $path, $healthTimeout, $newPid)) {
            $this->components->error('New server did not become healthy in time; terminating it.');
            @posix_kill($newPid, SIGTERM);

            return self::FAILURE;
        }

        $this->components->info("New server healthy; draining old server (PID: {$oldPid}).");

        return $this->waitForExit($oldPid, $drainTimeout, $termTimeout);
    }

    /**
     * Resolve the address the running server is actually serving.
     *
     * `resonate:start` records its effective host, port and path next to the
     * PID file, because those may have come from `--host`, `--port` or `--path`
     * and be nowhere in the config. Reading them back means the replacement is
     * spawned with the same bindings and the probe polls the address someone is
     * actually listening on. Config is the fallback for a server started before
     * the runtime file existed.
     *
     * @return array{host: string, port: int, path: string}
     */
    protected function serverOptions(int $pid): array
    {
        $runtime = StartServer::readRuntime($pid);

        if ($runtime !== null) {
            return [
                'host' => $runtime['host'],
                'port' => $runtime['port'],
                'path' => $runtime['path'],
            ];
        }

        /** @var ConfigRepository $repository */
        $repository = $this->laravel->make('config');

        /** @var array<string, mixed> $config */
        $config = $repository->get('reverb.servers.reverb');

        $this->components->warn(
            'No runtime metadata for the running server; falling back to the configured host and port. '.
            'Any --host, --port or --path the server was started with will not be carried over.'
        );

        return [
            'host' => (string) ($config['host'] ?? '0.0.0.0'),
            'port' => (int) ($config['port'] ?? 8080),
            'path' => (string) ($config['path'] ?? ''),
        ];
    }

    /**
     * Signal the old server to drain and wait for it to exit.
     *
     * The drain timeout on the server side acts as a hard upper bound; we
     * wait a few extra seconds here so the watchdog has time to fire and
     * the process to actually exit before we escalate to SIGTERM.
     *
     * A SIGTERM that is never observed to take effect is a failure, not a
     * success: a wedged old process keeps sharing the port with the new one,
     * and reporting exit code 0 there would let a deploy pipeline move on with
     * two servers splitting accepts.
     */
    protected function waitForExit(int $pid, int $timeout, int $termTimeout = 5): int
    {
        if (! @posix_kill($pid, SIGUSR2)) {
            $this->components->error("Failed to signal PID {$pid} (SIGUSR2).");

            return self::FAILURE;
        }

        if ($this->pollForExit($pid, $timeout + 5)) {
            $this->components->info("Old server (PID: {$pid}) exited cleanly.");

            return self::SUCCESS;
        }

        $this->components->warn("Old server (PID: {$pid}) did not exit within the drain window; sending SIGTERM.");
        @posix_kill($pid, SIGTERM);

        if ($this->pollForExit($pid, $termTimeout)) {
            $this->components->info("Old server (PID: {$pid}) exited after SIGTERM.");

            return self::SUCCESS;
        }

        $this->components->error(
            "Old server (PID: {$pid}) is still running after SIGTERM. ".
            'Two servers are now sharing the port; kill it manually before continuing.'
        );

        return self::FAILURE;
    }

    /**
     * Poll until the given process is gone or the deadline passes.
     */
    protected function pollForExit(int $pid, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        while (true) {
            if (! @posix_kill($pid, 0)) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(200_000);
        }
    }

    /**
     * Spawn a detached `resonate:start` child process.
     *
     * `--force` is passed because the running server's PID file is still in
     * place: overlapping for the length of the swap is the whole point here,
     * which is exactly the case the double-start guard makes an exception for.
     *
     * @param  array{host: string, port: int, path: string}  $options
     */
    protected function spawn(array $options): ?int
    {
        if (is_callable(static::$spawner)) {
            return (static::$spawner)($options);
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

        $command = [
            PHP_BINARY,
            $artisan,
            'resonate:start',
            '--force',
            '--host='.$options['host'],
            '--port='.$options['port'],
        ];

        if ($options['path'] !== '') {
            $command[] = '--path='.$options['path'];
        }

        $process = @proc_open($command, $descriptors, $pipes, base_path());

        if (! is_resource($process)) {
            return null;
        }

        $status = proc_get_status($process);

        return $status['pid'];
    }

    /**
     * Poll `/up` until the spawned PID answers it, or we time out.
     *
     * Two things have to hold before the old server may be drained: the child
     * is still alive, and the process answering the health check is that same
     * child. Checking only for a 200 was the dangerous version, since the old
     * server shares the port and happily answers on the dead child's behalf.
     */
    protected function waitForHealth(string $host, int $port, string $path, int $timeout, int $expectedPid): bool
    {
        $checkHost = $host === '0.0.0.0' ? '127.0.0.1' : $host;
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            if (! @posix_kill($expectedPid, 0)) {
                $this->components->error("Replacement server (PID: {$expectedPid}) exited during startup.");

                return false;
            }

            if ($this->probe($checkHost, $port, $path) === $expectedPid) {
                return true;
            }

            usleep(250_000);
        }

        return false;
    }

    /**
     * Issue an HTTP/1.0 GET /up and return the PID the server reported.
     *
     * Returns null when the request failed, the status was not a 200, or the
     * body carried no PID (a server from before the identity was added).
     */
    protected function probe(string $host, int $port, string $path = ''): ?int
    {
        if (is_callable(static::$probe)) {
            $reported = (static::$probe)($host, $port, $path);

            return $reported === null ? null : (int) $reported;
        }

        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 1);

        if (! is_resource($socket)) {
            return null;
        }

        stream_set_timeout($socket, 1);

        $uri = rtrim($path, '/').'/up';

        @fwrite($socket, "GET {$uri} HTTP/1.0\r\nHost: {$host}:{$port}\r\nConnection: close\r\n\r\n");
        $response = @stream_get_contents($socket, 4096);
        @fclose($socket);

        if (! is_string($response)) {
            return null;
        }

        if (! str_starts_with($response, 'HTTP/1.0 200') && ! str_starts_with($response, 'HTTP/1.1 200')) {
            return null;
        }

        return $this->pidFromHealthBody($response);
    }

    /**
     * Pull the `pid` field out of a raw `/up` response.
     */
    protected function pidFromHealthBody(string $response): ?int
    {
        $body = strstr($response, "\r\n\r\n");

        if ($body === false) {
            return null;
        }

        $decoded = json_decode(substr($body, 4), true);

        if (! is_array($decoded) || ! isset($decoded['pid'])) {
            return null;
        }

        $pid = (int) $decoded['pid'];

        return $pid > 0 ? $pid : null;
    }
}

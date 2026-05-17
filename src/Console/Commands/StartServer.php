<?php

namespace Webpatser\Resonate\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Laravel\Pulse\Pulse;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\Telescope;
use Revolt\EventLoop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Webpatser\Resonate\Contracts\Logger;
use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Jobs\PingInactiveConnections;
use Webpatser\Resonate\Jobs\PruneStaleConnections;
use Webpatser\Resonate\Loggers\CliLogger;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;
use Webpatser\Resonate\Server\Factory as ServerFactory;
use Webpatser\Resonate\Server\HttpServer;

#[AsCommand(name: 'resonate:start')]
class StartServer extends Command implements SignalableCommandInterface
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resonate:start
                {--host= : The IP address the server should bind to}
                {--port= : The port the server should listen on}
                {--path= : The path the server should prefix to all routes}
                {--hostname= : The hostname the server is accessible from}
                {--debug : Indicates whether debug messages should be displayed in the terminal}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the Resonate server';

    /**
     * The running HTTP server instance.
     */
    protected ?HttpServer $server = null;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        if ($this->option('debug')) {
            $this->laravel->instance(Logger::class, new CliLogger($this->output));
        }

        $config = $this->laravel['config']['reverb.servers.reverb'];

        $this->server = ServerFactory::make(
            $host = $this->option('host') ?: $config['host'],
            $port = $this->option('port') ?: $config['port'],
            $path = $this->option('path') ?: $config['path'] ?? '',
            $hostname = $this->option('hostname') ?: $config['hostname'],
            $config['max_request_size'] ?? 10_000,
            $config['options'] ?? [],
            EventLoop::getDriver(),
        );

        $this->ensureRestartCommandIsRespected($this->server, $host, $port);
        $this->ensureMemoryIsReclaimed();
        $this->ensureHorizontalScalability();
        $this->ensureStaleConnectionsAreCleaned();
        $this->ensurePulseEventsAreCollected($config['pulse_ingest_interval'] ?? 15);
        $this->ensureTelescopeEntriesAreCollected($config['telescope_ingest_interval'] ?? 15);

        $this->writePidFile();

        $this->components->info("Starting server on {$host}:{$port}{$path}".(($hostname && $hostname !== $host) ? " ({$hostname})" : ''));

        try {
            $this->server->start();
        } finally {
            $this->removePidFile();
        }
    }

    /**
     * Connect the pub/sub provider when horizontal scaling is enabled.
     *
     * `RedisPubSubProvider::connect()` schedules its subscribe fiber on the
     * ambient Revolt loop, so calling it here (after the server is built but
     * before `$server->start()` enters `EventLoop::run()`) is enough to have
     * the listener running once the loop starts. When scaling is off the
     * `ServerProvider` binding is absent and this is a no-op.
     */
    protected function ensureHorizontalScalability(): void
    {
        if (app()->bound(ServerProvider::class) && app(ServerProvider::class)->subscribesToEvents()) {
            app(PubSubProvider::class)->connect();
        }
    }

    /**
     * Check periodically whether the restart signal has been broadcast.
     */
    protected function ensureRestartCommandIsRespected(HttpServer $server, string $host, string|int $port): void
    {
        $lastRestart = Cache::get('laravel:reverb:restart');

        EventLoop::repeat(5, function () use ($server, $host, $port, $lastRestart): void {
            if ($lastRestart === Cache::get('laravel:reverb:restart')) {
                return;
            }

            $this->components->info("Stopping server on {$host}:{$port}");

            $server->stop();
        });
    }

    /**
     * Periodically reclaim cyclic garbage.
     *
     * fledge-fiber leaves gc enabled, but the protocol layer holds long-lived
     * references; an explicit collection on a slow timer keeps memory flat
     * without paying the gc cost on every request.
     */
    protected function ensureMemoryIsReclaimed(): void
    {
        EventLoop::repeat(30, static fn () => gc_collect_cycles());
    }

    /**
     * Periodically prune stale connections and ping inactive ones.
     */
    protected function ensureStaleConnectionsAreCleaned(): void
    {
        EventLoop::repeat(60, static function (): void {
            PruneStaleConnections::dispatch();
            PingInactiveConnections::dispatch();
        });
    }

    /**
     * Schedule Pulse to ingest events when Pulse is installed.
     */
    protected function ensurePulseEventsAreCollected(int $interval): void
    {
        if (! class_exists(Pulse::class) || ! $this->laravel->bound(Pulse::class)) {
            return;
        }

        EventLoop::repeat($interval, fn () => $this->laravel->make(Pulse::class)->ingest());
    }

    /**
     * Schedule Telescope to store entries when Telescope is installed.
     */
    protected function ensureTelescopeEntriesAreCollected(int $interval): void
    {
        if (
            ! class_exists(Telescope::class)
            || ! class_exists(EntriesRepository::class)
            || ! $this->laravel->bound(EntriesRepository::class)
        ) {
            return;
        }

        EventLoop::repeat(
            $interval,
            fn () => Telescope::store($this->laravel->make(EntriesRepository::class)),
        );
    }

    /**
     * Get the list of signals handled by the command.
     *
     * @return array<int, int>
     */
    public function getSubscribedSignals(): array
    {
        if (! windows_os()) {
            return [SIGINT, SIGTERM, SIGTSTP, SIGUSR2];
        }

        $this->handleSignalWindows();

        return [];
    }

    /**
     * Handle the signals sent to the server.
     *
     * SIGUSR2 triggers a graceful drain: stop accepting new connections, let
     * in-flight ones finish, and exit after the configured drain timeout.
     * All other handled signals fall back to the hard `stop()` path.
     */
    public function handleSignal(int $signal = 0, int|false $previousExitCode = 0): int|false
    {
        if (defined('SIGUSR2') && $signal === SIGUSR2) {
            $timeout = (int) ($this->laravel['config']['reverb.servers.reverb.drain_timeout'] ?? 30);

            $this->components->info("Draining the server (timeout: {$timeout}s).");

            $this->server?->drain($timeout);

            return $previousExitCode;
        }

        $this->components->info('Gracefully stopping the server.');

        $this->server?->stop();

        return $previousExitCode;
    }

    /**
     * Handle the signals sent to the server on Windows.
     */
    public function handleSignalWindows(): void
    {
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(fn () => exit($this->handleSignal()));
        }
    }

    /**
     * Get the path to the PID file.
     */
    public static function pidFilePath(): string
    {
        return storage_path('resonate.pid');
    }

    /**
     * Write the server PID to the PID file atomically.
     *
     * Refuses to start if the PID path already exists as a symlink: unlinking
     * and recreating opens a TOCTOU window where an attacker with write access
     * to the storage directory could redirect the rename.
     */
    protected function writePidFile(): void
    {
        $path = static::pidFilePath();

        if (is_link($path)) {
            throw new \RuntimeException("Refusing to start: PID path {$path} is a symlink.");
        }

        $tmpPath = $path.'.'.getmypid().'.tmp';

        if (file_put_contents($tmpPath, (string) getmypid(), LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write temporary PID file at {$tmpPath}.");
        }

        if (! rename($tmpPath, $path)) {
            @unlink($tmpPath);

            throw new \RuntimeException("Failed to move PID file to {$path}.");
        }
    }

    /**
     * Remove the PID file on shutdown.
     *
     * After a zero-downtime reload the new server has already rewritten
     * `storage/resonate.pid` with its own PID; we must not clobber that when
     * the old server finishes draining, so only unlink when the file still
     * points at our own PID.
     */
    protected function removePidFile(): void
    {
        $path = static::pidFilePath();

        if (! file_exists($path) || is_link($path)) {
            return;
        }

        $pidInFile = (int) @file_get_contents($path);

        if ($pidInFile === getmypid()) {
            @unlink($path);
        }
    }

    /**
     * Read the running server PID from the PID file, or null if not running.
     */
    public static function readPid(): ?int
    {
        $path = static::pidFilePath();

        if (! file_exists($path) || is_link($path)) {
            return null;
        }

        $pid = (int) file_get_contents($path);

        if ($pid <= 0) {
            return null;
        }

        if (function_exists('posix_kill') && posix_kill($pid, 0)) {
            return $pid;
        }

        @unlink($path);

        return null;
    }
}

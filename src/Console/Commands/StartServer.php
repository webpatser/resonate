<?php

namespace Webpatser\Resonate\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use Laravel\Pulse\Pulse;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\Telescope;
use Revolt\EventLoop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Webpatser\Resonate\Application as ResonateApplication;
use Webpatser\Resonate\Contracts\Logger;
use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Exceptions\InvalidConfiguration;
use Webpatser\Resonate\Jobs\PingInactiveConnections;
use Webpatser\Resonate\Jobs\PruneStaleConnections;
use Webpatser\Resonate\Loggers\CliLogger;
use Webpatser\Resonate\Loggers\Log;
use Webpatser\Resonate\Plugins\PluginManager;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;
use Webpatser\Resonate\Scheduling\Scheduler;
use Webpatser\Resonate\Server\ApplicationClientFactory;
use Webpatser\Resonate\Server\Factory as ServerFactory;
use Webpatser\Resonate\Server\HttpServer;
use Webpatser\Resonate\Server\RawConnection;

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
                {--force : Start even when the PID file names a running server (used by resonate:reload)}
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
     * The event-loop scheduler for the server's periodic tasks.
     */
    protected ?Scheduler $scheduler = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->option('force') && ($runningPid = static::readPid()) !== null) {
            $this->components->error(
                "A Resonate server is already running (PID: {$runningPid}). ".
                'Stop it first, use resonate:reload for a zero-downtime swap, or pass --force to start anyway.'
            );

            return self::FAILURE;
        }

        if (($invalid = $this->applicationConfigurationError()) !== null) {
            $this->components->error($invalid);

            return self::FAILURE;
        }

        if ($this->option('debug')) {
            $this->laravel->instance(Logger::class, new CliLogger($this->output));
        }

        /** @var array<string, mixed> $config */
        $config = $this->configRepository()->get('reverb.servers.reverb');

        $this->server = ServerFactory::make(
            $host = $this->option('host') ?: $config['host'],
            $port = $this->option('port') ?: $config['port'],
            $path = $this->option('path') ?: $config['path'] ?? '',
            $hostname = $this->option('hostname') ?: $config['hostname'],
            $config['max_request_size'] ?? 10_000,
            $config['options'] ?? [],
            EventLoop::getDriver(),
            $this->fallbackMessageSize(),
            (int) ($config['max_outbound_queue_size'] ?? RawConnection::DEFAULT_MAX_QUEUE_SIZE),
        );

        $this->scheduler = app(Scheduler::class);

        $this->ensureRestartCommandIsRespected($this->server, $host, $port);
        $this->ensureMemoryIsReclaimed();
        $this->ensureHorizontalScalability();
        $this->ensurePluginsAreScheduled();
        $this->ensureStaleConnectionsAreCleaned();
        $this->ensurePulseEventsAreCollected($config['pulse_ingest_interval'] ?? 15);
        $this->ensureTelescopeEntriesAreCollected($config['telescope_ingest_interval'] ?? 15);

        // The PID file is the discovery handle for `resonate:reload`, so it may
        // only be published once this process is actually accepting. Writing it
        // at boot meant a replacement that died during startup left the file
        // pointing at a dead PID while the still-serving old server became
        // undiscoverable, and the next reload errored with "no running server
        // found" after unlinking it. `onListening` fires after the listening
        // sockets are bound and the accept loops are queued.
        $this->server->onListening(fn () => $this->publishRuntimeState($host, (int) $port, $path));

        // Belt and braces for the paths that bypass the finally below, such as
        // the Windows control handler or a fatal error mid-loop.
        register_shutdown_function(fn () => $this->removePidFile());

        $this->components->info("Starting server on {$host}:{$port}{$path}".(($hostname && $hostname !== $host) ? " ({$hostname})" : ''));

        try {
            $this->server->start();
        } finally {
            $this->removePidFile();
        }

        return self::SUCCESS;
    }

    /**
     * Get the application's configuration repository.
     *
     * Equivalent to `$this->laravel['config']`, which the container resolves
     * through the same `make()` call, but with a type the analyser can follow.
     */
    protected function configRepository(): ConfigRepository
    {
        /** @var ConfigRepository $config */
        $config = $this->laravel->make('config');

        return $config;
    }

    /**
     * Get the first configured application error, if any.
     *
     * Validated against the raw config rather than through the application
     * provider, so a server can still start while an unrelated application
     * entry is incomplete.
     */
    protected function applicationConfigurationError(): ?string
    {
        /** @var array<array-key, array<string, mixed>> $apps */
        $apps = $this->configRepository()->get('reverb.apps.apps') ?? [];

        foreach ($apps as $index => $app) {
            try {
                ResonateApplication::ensureRateLimitingIsValid(
                    $app['rate_limiting'] ?? null,
                    (string) ($app['app_id'] ?? $index),
                );
            } catch (InvalidConfiguration $e) {
                return $e->getMessage();
            }
        }

        return null;
    }

    /**
     * Resolve the fallback websocket message size limit.
     *
     * Each connection's parser is sized from its own application's
     * `max_message_size` by {@see ApplicationClientFactory}. This value only
     * covers an upgrade whose app key does not resolve to an application, so
     * it is the *smallest* configured limit: an unknown app is closed with
     * pusher code 4001 the moment the handler takes over, and until then it
     * should not be able to buffer more than the most restrictive tenant can.
     *
     * It used to be the largest configured limit, applied to every connection,
     * which let a client of a 10KB app buffer up to a 10MB app's limit before
     * Pusher\Server::message() rejected it, over and over.
     */
    protected function fallbackMessageSize(): int
    {
        /** @var array<array-key, array<string, mixed>> $apps */
        $apps = $this->configRepository()->get('reverb.apps.apps') ?? [];

        $sizes = collect($apps)
            ->map(fn ($app) => (int) ($app['max_message_size'] ?? 0))
            ->filter(fn (int $size) => $size > 0);

        return $sizes->isEmpty() ? 10_000 : (int) $sizes->min();
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

        $this->scheduler->repeat(5, function () use ($server, $host, $port, $lastRestart): void {
            if ($lastRestart === Cache::get('laravel:reverb:restart')) {
                return;
            }

            $this->components->info("Stopping server on {$host}:{$port}");

            $server->stop();
        }, 'restart:poll');
    }

    /**
     * Boot the server-side plugins and schedule their periodic ticks.
     *
     * Plugins are booted before `$server->start()` enters the loop. Each tick
     * is handed to the `Scheduler`, which runs it inside a fiber and isolates
     * any failure so it can never cancel the timer. When no plugins are
     * configured this is a no-op.
     */
    protected function ensurePluginsAreScheduled(): void
    {
        $manager = app(PluginManager::class);

        if (! $manager->hasPlugins()) {
            return;
        }

        $manager->boot();

        foreach ($manager->ticks() as $tick) {
            $this->scheduler->repeat($tick['interval'], $tick['callback'], 'plugin:tick');
        }
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
        $this->scheduler->repeat(30, static fn () => gc_collect_cycles(), 'memory:gc');
    }

    /**
     * Periodically prune stale connections and ping inactive ones.
     */
    protected function ensureStaleConnectionsAreCleaned(): void
    {
        $this->scheduler->repeat(60, static function (): void {
            PruneStaleConnections::dispatch();
            PingInactiveConnections::dispatch();
        }, 'connections:maintenance');
    }

    /**
     * Schedule Pulse to ingest events when Pulse is installed.
     */
    protected function ensurePulseEventsAreCollected(int $interval): void
    {
        if (! class_exists(Pulse::class) || ! $this->laravel->bound(Pulse::class)) {
            return;
        }

        $this->scheduler->repeat($interval, fn () => $this->laravel->make(Pulse::class)->ingest(), 'pulse:ingest');
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

        $this->scheduler->repeat(
            $interval,
            fn () => Telescope::store($this->laravel->make(EntriesRepository::class)),
            'telescope:ingest',
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
     *
     * Returning anything other than `false` makes Symfony call `exit()` the
     * moment this method returns, which would kill the process before either
     * the drain window or the queued loop-stop could run, severing every live
     * connection. Both paths therefore return `false` and hand the actual work
     * to the event loop: `drain()` schedules a watchdog and `stop()` queues a
     * driver stop, so `start()` unwinds on its own and `handle()` completes.
     *
     * The work is deferred rather than performed inline because with
     * `pcntl_async_signals` this method runs between opcodes of whatever fiber
     * happens to be executing. Doing console I/O and closing listener sockets
     * there can interleave with a partially written frame.
     */
    public function handleSignal(int $signal = 0, int|false $previousExitCode = 0): int|false
    {
        if (defined('SIGUSR2') && $signal === SIGUSR2) {
            $timeout = (int) ($this->configRepository()->get('reverb.servers.reverb.drain_timeout') ?? 30);

            EventLoop::defer(function () use ($timeout) {
                $this->components->info("Draining the server (timeout: {$timeout}s).");

                $this->scheduler?->cancelAll();

                $this->server?->drain($timeout);
            });

            return false;
        }

        EventLoop::defer(function () {
            $this->components->info('Gracefully stopping the server.');

            $this->scheduler?->cancelAll();

            $this->server?->stop();
        });

        return false;
    }

    /**
     * Handle the signals sent to the server on Windows.
     */
    public function handleSignalWindows(): void
    {
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(function (): void {
                $this->handleSignal();

                exit(0);
            });
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
     * Get the path to the runtime metadata file.
     *
     * Sits next to the PID file and records the address this process actually
     * bound to. `resonate:reload` needs it because the running server may have
     * been started with `--host`, `--port` or `--path` overrides that are
     * nowhere in the config: without them the replacement would bind the config
     * port and the health probe would poll an address nobody is serving.
     */
    public static function runtimeFilePath(): string
    {
        return storage_path('resonate.json');
    }

    /**
     * Read the runtime metadata for the running server.
     *
     * Returns null when the file is missing, unreadable, not the expected
     * shape, or (when `$expectedPid` is given) when it describes a different
     * process than the one we are about to act on.
     *
     * @return array{pid: int, host: string, port: int, path: string}|null
     */
    public static function readRuntime(?int $expectedPid = null): ?array
    {
        $path = static::runtimeFilePath();

        if (! file_exists($path) || is_link($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if (! is_string($contents)) {
            return null;
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded) || ! isset($decoded['pid'], $decoded['host'], $decoded['port'])) {
            return null;
        }

        $pid = (int) $decoded['pid'];

        if ($expectedPid !== null && $pid !== $expectedPid) {
            return null;
        }

        return [
            'pid' => $pid,
            'host' => (string) $decoded['host'],
            'port' => (int) $decoded['port'],
            'path' => (string) ($decoded['path'] ?? ''),
        ];
    }

    /**
     * Publish this process as the running server.
     *
     * Called from the server's `onListening` hook, so by the time the PID file
     * exists the process behind it is genuinely accepting connections.
     */
    protected function publishRuntimeState(string $host, int $port, string $path): void
    {
        $this->writePidFile();
        $this->writeRuntimeFile($host, $port, $path);
    }

    /**
     * Write the runtime metadata file atomically.
     *
     * Mirrors {@see writePidFile()}: same symlink refusal, same tmp + rename.
     * A failure here is logged rather than thrown, because the server is
     * already accepting connections at this point and losing the reload hint
     * is not worth tearing a serving process down for.
     */
    protected function writeRuntimeFile(string $host, int $port, string $path): void
    {
        $target = static::runtimeFilePath();

        if (is_link($target)) {
            Log::error("Refusing to write runtime metadata: {$target} is a symlink.");

            return;
        }

        $payload = json_encode([
            'pid' => getmypid(),
            'host' => $host,
            'port' => $port,
            'path' => $path,
        ]);

        $tmpPath = $target.'.'.getmypid().'.tmp';

        if ($payload === false || file_put_contents($tmpPath, $payload, LOCK_EX) === false) {
            Log::error("Failed to write runtime metadata at {$target}.");

            return;
        }

        if (! rename($tmpPath, $target)) {
            @unlink($tmpPath);

            Log::error("Failed to move runtime metadata to {$target}.");
        }
    }

    /**
     * Remove the runtime metadata file on shutdown.
     *
     * Same "only mine" rule as {@see removePidFile()}: after a reload the new
     * server has already rewritten this file, and the draining old server must
     * not delete it.
     */
    protected function removeRuntimeFile(): void
    {
        $path = static::runtimeFilePath();

        if (! file_exists($path) || is_link($path)) {
            return;
        }

        $runtime = static::readRuntime();

        if ($runtime !== null && $runtime['pid'] === getmypid()) {
            @unlink($path);
        }
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
     * Remove the PID file (and the runtime metadata beside it) on shutdown.
     *
     * After a zero-downtime reload the new server has already rewritten
     * `storage/resonate.pid` with its own PID; we must not clobber that when
     * the old server finishes draining, so only unlink when the file still
     * points at our own PID.
     */
    protected function removePidFile(): void
    {
        $path = static::pidFilePath();

        if (file_exists($path) && ! is_link($path) && (int) @file_get_contents($path) === getmypid()) {
            @unlink($path);
        }

        $this->removeRuntimeFile();
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

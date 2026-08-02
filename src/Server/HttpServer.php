<?php

namespace Webpatser\Resonate\Server;

use Closure;
use Fledge\Async\Http\Server\DefaultErrorHandler;
use Fledge\Async\Http\Server\ErrorHandler;
use Fledge\Async\Http\Server\HttpServerStatus;
use Fledge\Async\Http\Server\RequestHandler;
use Fledge\Async\Http\Server\SocketHttpServer;
use ReflectionObject;
use Revolt\EventLoop;
use Throwable;
use Webpatser\Resonate\Loggers\Log;

/**
 * Thin wrapper around the fledge-fiber HTTP server.
 *
 * Equivalent to Reverb's Http\Server, but the underlying transport is the
 * fledge-fiber {@see SocketHttpServer} driven by the Revolt event loop instead
 * of a ReactPHP socket server.
 */
class HttpServer
{
    /**
     * The error handler used for transport-level HTTP errors.
     */
    protected ErrorHandler $errorHandler;

    /**
     * Callbacks to run when the server stops.
     *
     * @var array<int, callable>
     */
    protected array $onStop = [];

    /**
     * Callbacks to run once the server is accepting connections.
     *
     * @var array<int, callable>
     */
    protected array $onListening = [];

    /**
     * Whether a drain has already been initiated for this instance.
     */
    protected bool $draining = false;

    /**
     * How often the drain watcher checks whether the last client has gone.
     */
    protected float $drainPollInterval = 0.1;

    /**
     * Revolt callback id of the drain watchdog, if a drain is in progress.
     */
    protected ?string $drainWatchdog = null;

    /**
     * Revolt callback id of the drain completion poller, if one is running.
     */
    protected ?string $drainPoller = null;

    /**
     * Create a new HTTP server instance.
     */
    public function __construct(
        protected SocketHttpServer $server,
        protected RequestHandler $router,
        ?ErrorHandler $errorHandler = null,
    ) {
        $this->errorHandler = $errorHandler ?: new DefaultErrorHandler;
    }

    /**
     * Get the underlying fledge-fiber socket HTTP server.
     */
    public function base(): SocketHttpServer
    {
        return $this->server;
    }

    /**
     * Register a callback to run when the server stops.
     */
    public function onStop(callable $callback): void
    {
        $this->onStop[] = $callback;
        $this->server->onStop(fn () => $callback());
    }

    /**
     * Register a callback to run once the server is accepting connections.
     *
     * Deliberately not `SocketHttpServer::onStart()`: those callbacks are
     * awaited *before* the listening sockets are bound, so they fire while the
     * port is still closed. These run after `start()` has returned, at which
     * point the listeners are bound and the accept loops are queued.
     */
    public function onListening(callable $callback): void
    {
        $this->onListening[] = $callback;
    }

    /**
     * Start the HTTP server and run the event loop.
     *
     * fledge-fiber's start() queues the accept loops onto the Revolt event
     * loop and returns immediately, so the loop must then be run to actually
     * serve connections. This call blocks until the loop is stopped.
     */
    public function start(): void
    {
        $this->server->start($this->router, $this->errorHandler);

        foreach ($this->onListening as $callback) {
            $callback();
        }

        try {
            EventLoop::run();
        } catch (Throwable $e) {
            Log::error($e->getMessage());
        }
    }

    /**
     * Stop the HTTP server and the event loop.
     */
    public function stop(): void
    {
        if ($this->server->getStatus() === HttpServerStatus::Started) {
            $this->server->stop();
        }

        EventLoop::queue(static fn () => EventLoop::getDriver()->stop());
    }

    /**
     * Stop accepting new connections and let in-flight ones finish.
     *
     * Calling `SocketHttpServer::stop()` would also fire its `onStop`
     * callbacks (the fledge-fiber Websocket server uses one to close every
     * active client with code 1001 GOING_AWAY) and cancel the HTTP drivers
     * mid-request, which is exactly what `stop()` does and what drain
     * needs to avoid. Instead we reach into the vendor object and close just
     * the listening server sockets so existing WebSocket and HTTP/1.1 keep-
     * alive connections survive.
     *
     * `$timeout` is an upper bound, not a wait: a poller finishes the drain as
     * soon as the last client has gone, so a deploy on an idle node no longer
     * burns the full `drain_timeout`. The timeout still hard-stops the loop when
     * clients refuse to disconnect.
     */
    public function drain(int $timeout): void
    {
        if ($this->draining) {
            return;
        }

        if ($this->server->getStatus() !== HttpServerStatus::Started) {
            return;
        }

        $this->draining = true;

        if (! $this->closeListeners()) {
            // The vendor internals moved under us. Continuing would leave every
            // listener open for the whole drain window and beyond while looking
            // like a successful drain, so fall back to the hard stop, which uses
            // only public vendor API.
            Log::error(
                'Unable to drain: '.SocketHttpServer::class.'::$servers no longer exists. '.
                'Falling back to an immediate stop.'
            );

            $this->stop();

            return;
        }

        $this->drainWatchdog = EventLoop::delay($timeout, fn () => $this->finishDrain());

        $this->drainPoller = EventLoop::repeat($this->drainPollInterval, function (): void {
            $active = $this->activeClientCount();

            if ($active === null) {
                // No way to observe the client count, so the watchdog is the
                // only exit; stop burning a timer on every tick.
                $this->cancelDrainWatcher($this->drainPoller);
                $this->drainPoller = null;

                return;
            }

            if ($active > 0) {
                return;
            }

            $this->finishDrain();
        });
    }

    /**
     * Cancel the drain timers and stop the server.
     *
     * Reached either from the poller (the last client has gone) or from the
     * watchdog (the timeout expired with clients still attached).
     */
    protected function finishDrain(): void
    {
        $this->cancelDrainWatcher($this->drainPoller);
        $this->cancelDrainWatcher($this->drainWatchdog);

        $this->drainPoller = null;
        $this->drainWatchdog = null;

        $this->hardStop();
    }

    /**
     * Cancel one of the drain timers when it is still registered.
     */
    protected function cancelDrainWatcher(?string $id): void
    {
        if ($id !== null) {
            EventLoop::cancel($id);
        }
    }

    /**
     * Close the listening sockets without touching in-flight connections.
     *
     * Returns false when the vendor property this reaches for is gone, which is
     * the signal for {@see drain()} to take the hard-stop path instead of
     * silently doing nothing.
     */
    protected function closeListeners(): bool
    {
        if (! $this->serverHasProperty('servers')) {
            return false;
        }

        $closeListeners = Closure::bind(function (): void {
            foreach ($this->servers as $listener) {
                $listener->close();
            }

            $this->servers = [];
        }, $this->server, SocketHttpServer::class);

        $closeListeners();

        return true;
    }

    /**
     * Count the clients still being served, or null when it cannot be observed.
     *
     * fledge-fiber keeps one HTTP driver per accepted client in a private map
     * and removes the entry when that client's handler returns, so its size is
     * the live connection count (upgraded WebSocket clients included, since
     * their driver stays in the map for the life of the socket).
     */
    protected function activeClientCount(): ?int
    {
        if (! $this->serverHasProperty('drivers')) {
            return null;
        }

        $count = Closure::bind(function (): int {
            return count($this->drivers);
        }, $this->server, SocketHttpServer::class);

        return $count();
    }

    /**
     * Determine whether the vendor server still declares the given property.
     *
     * Checked at runtime, against the installed fledge-fiber, because both
     * properties this class reaches into are private vendor internals. Static
     * analysis can only confirm they exist in the version pinned today, which
     * is precisely not the question: the guard is here so a rename in a future
     * fledge-fiber surfaces as a loud fallback instead of a drain that closes
     * nothing and reports success.
     */
    protected function serverHasProperty(string $property): bool
    {
        return (new ReflectionObject($this->server))->hasProperty($property);
    }

    /**
     * Stop the underlying server and the loop, isolating any failure.
     */
    protected function hardStop(): void
    {
        try {
            if ($this->server->getStatus() === HttpServerStatus::Started) {
                $this->server->stop();
            }
        } catch (Throwable $e) {
            Log::error($e->getMessage());
        } finally {
            EventLoop::getDriver()->stop();
        }
    }
}

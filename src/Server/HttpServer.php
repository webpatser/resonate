<?php

namespace Webpatser\Resonate\Server;

use Closure;
use Fledge\Async\Http\Server\DefaultErrorHandler;
use Fledge\Async\Http\Server\ErrorHandler;
use Fledge\Async\Http\Server\HttpServerStatus;
use Fledge\Async\Http\Server\RequestHandler;
use Fledge\Async\Http\Server\SocketHttpServer;
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
     * Whether a drain has already been initiated for this instance.
     */
    protected bool $draining = false;

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
     * Start the HTTP server and run the event loop.
     *
     * fledge-fiber's start() queues the accept loops onto the Revolt event
     * loop and returns immediately, so the loop must then be run to actually
     * serve connections. This call blocks until the loop is stopped.
     */
    public function start(): void
    {
        $this->server->start($this->router, $this->errorHandler);

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
     * alive connections survive. A watchdog hard-stops the loop after
     * `$timeout` seconds if clients refuse to disconnect.
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

        $closeListeners = Closure::bind(function (): void {
            foreach ($this->servers as $listener) {
                $listener->close();
            }

            $this->servers = [];
        }, $this->server, SocketHttpServer::class);

        $closeListeners();

        EventLoop::delay($timeout, function (): void {
            try {
                if ($this->server->getStatus() === HttpServerStatus::Started) {
                    $this->server->stop();
                }
            } catch (Throwable $e) {
                Log::error($e->getMessage());
            } finally {
                EventLoop::getDriver()->stop();
            }
        });
    }
}

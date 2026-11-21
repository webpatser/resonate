<?php

namespace Webpatser\Resonate\Server;

use Fledge\Async\Http\Server\DefaultErrorHandler;
use Fledge\Async\Http\Server\Driver\DefaultHttpDriverFactory;
use Fledge\Async\Http\Server\SocketHttpServer;
use Fledge\Async\Stream\BindContext;
use Fledge\Async\Stream\Certificate;
use Fledge\Async\Stream\ServerTlsContext;
use Fledge\Async\WebSocket\Server\Rfc6455Acceptor;
use Fledge\Async\WebSocket\Server\Websocket;
use Fledge\Loop;
use Fledge\Loop\Driver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Exceptions\InvalidConfiguration;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\ChannelController;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\ChannelsController;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\ChannelUsersController;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\ConnectionsController;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\EventsBatchController;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\EventsController;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\HealthCheckController;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\UsersTerminateController;
use Webpatser\Resonate\Protocols\Pusher\Server as PusherServer;

/**
 * Builds the Resonate HTTP/WebSocket server on top of fledge-fiber.
 *
 * This is the fledge-fiber equivalent of Reverb's Servers\Reverb\Factory: it
 * wires a {@see SocketHttpServer}, configures TLS, attaches the Resonate
 * {@see Router}, and registers the {@see WebSocketHandler} for the
 * `/app/{appKey}` WebSocket upgrade route plus a `/up` health check.
 */
class Factory
{
    /**
     * Create a new HTTP server instance.
     *
     * `$loop` is made the event loop's driver when it is not already; the
     * `resonate:start` command passes the driver it installed from the
     * `event_loop` setting. `$tcpKeepAlive` is the `tcp_keepalive` setting,
     * see {@see keepAlive()}.
     *
     * @param  array<string, mixed>  $options  The server "options" config (notably `tls`).
     */
    public static function make(
        string $host = '0.0.0.0',
        string|int $port = 8080,
        string $path = '',
        ?string $hostname = null,
        int $maxRequestSize = 10_000,
        array $options = [],
        ?Driver $loop = null,
        int $fallbackMessageSize = 10_000,
        int $maxOutboundQueueSize = RawConnection::DEFAULT_MAX_QUEUE_SIZE,
        ?int $connectionLimit = null,
        ?int $connectionLimitPerIp = null,
        mixed $tcpKeepAlive = null,
    ): HttpServer {
        if ($loop !== null && $loop !== Loop::getDriver()) {
            Loop::setDriver($loop);
        }

        $logger = self::logger();

        $socketServer = static::makeSocketServer(
            $logger,
            $maxRequestSize,
            self::connectionLimit($connectionLimit, 'connection_limit'),
            self::connectionLimit($connectionLimitPerIp, 'connection_limit_per_ip'),
        );

        $socketServer->expose(
            "{$host}:{$port}",
            self::bindContext(self::configureTls($options['tls'] ?? [], $hostname), $tcpKeepAlive),
        );

        $router = self::makeRouter($path, $socketServer, $logger, $fallbackMessageSize, $maxOutboundQueueSize);

        return new HttpServer($socketServer, $router, new DefaultErrorHandler);
    }

    /**
     * Build the fledge-fiber socket server with the transport limits applied.
     *
     * @param  positive-int  $connectionLimit
     * @param  positive-int  $connectionLimitPerIp
     */
    protected static function makeSocketServer(
        LoggerInterface $logger,
        int $maxRequestSize,
        int $connectionLimit,
        int $connectionLimitPerIp,
    ): SocketHttpServer {
        // Enforce the configured `max_request_size` at the HTTP/1.1 + HTTP/2
        // driver level so an oversized body is rejected before it ever reaches
        // the controllers.
        //
        // The connection limits are passed explicitly: fledge-fiber defaults to
        // 1000 connections in total and 10 per IP, and behind a reverse proxy
        // every client shares the proxy's address, so the 11th client used to
        // be dropped without a log line. Unlimited unless configured.
        return SocketHttpServer::createForDirectAccess(
            logger: $logger,
            connectionLimit: $connectionLimit,
            connectionLimitPerIp: $connectionLimitPerIp,
            httpDriverFactory: new DefaultHttpDriverFactory(
                logger: $logger,
                bodySizeLimit: $maxRequestSize,
            ),
        );
    }

    /**
     * Build the listener's bind context.
     *
     * Always binds with SO_REUSEPORT so `resonate:reload` can spawn a second
     * process on the same port; the kernel routes new accepts to it while the
     * old process drains. With a single listener the flag is a no-op.
     *
     * TCP_NODELAY goes in as the listener's `tcp_nodelay` context option, and
     * PHP sets it on every socket it accepts, before any TLS handshake, so it
     * covers TLS connections too, which setting it per connection after the
     * upgrade could not (the option cannot be set through a stream that has
     * crypto enabled). The listening socket itself does not carry it. Without
     * it every small frame waits on the kernel's coalescing timer, which is
     * exactly wrong for a protocol of small, latency-sensitive messages. TCP
     * keep-alive, when configured, is set on the listening socket, and
     * accepted sockets inherit it.
     */
    public static function bindContext(?BindContext $context = null, mixed $tcpKeepAlive = null): BindContext
    {
        $context = ($context ?? new BindContext)->withReusePort()->withTcpNoDelay();

        return match ($idle = self::keepAlive($tcpKeepAlive)) {
            false => $context,
            true => $context->withKeepAlive(),
            default => $context->withKeepAlive(idle: $idle),
        };
    }

    /**
     * Resolve the `tcp_keepalive` setting.
     *
     * Null, false, an empty string and 0 turn TCP keep-alive off (the
     * default: the Pusher protocol already pings idle clients and prunes the
     * ones that stop answering). True turns it on with the operating system's
     * timings; a positive integer turns it on and sets the idle seconds before
     * the first probe.
     *
     * @return bool|positive-int
     *
     * @throws InvalidConfiguration
     */
    public static function keepAlive(mixed $value): bool|int
    {
        if ($value === null || $value === false || $value === '' || $value === 0 || $value === '0') {
            return false;
        }

        if ($value === true) {
            return true;
        }

        $idle = is_int($value) ? $value : filter_var($value, FILTER_VALIDATE_INT);

        if ($idle === false || $idle < 1) {
            throw new InvalidConfiguration(sprintf(
                'Invalid servers.reverb.tcp_keepalive value [%s]: expected false, true, or the idle seconds before the first probe.',
                is_scalar($value) ? (string) $value : get_debug_type($value),
            ));
        }

        return $idle;
    }

    /**
     * Resolve a configured transport connection limit.
     *
     * Null, an empty string and 0 mean unlimited and map to PHP_INT_MAX (the
     * transport requires a positive limit). Anything that is not a
     * non-negative integer is rejected, so a typo fails at startup instead of
     * silently capping the node.
     *
     * @return positive-int
     *
     * @throws InvalidConfiguration
     */
    public static function connectionLimit(mixed $value, string $key = 'connection_limit'): int
    {
        if ($value === null || $value === '') {
            return PHP_INT_MAX;
        }

        $limit = is_int($value) ? $value : filter_var($value, FILTER_VALIDATE_INT);

        if ($limit === false || $limit < 0) {
            throw new InvalidConfiguration(sprintf(
                'Invalid servers.reverb.%s value [%s]: expected a non-negative integer, or 0 or null for unlimited.',
                $key,
                is_scalar($value) ? (string) $value : get_debug_type($value),
            ));
        }

        return $limit === 0 ? PHP_INT_MAX : $limit;
    }

    /**
     * Build the Resonate router with the WebSocket and health check routes.
     */
    protected static function makeRouter(
        string $path,
        SocketHttpServer $socketServer,
        LoggerInterface $logger,
        int $fallbackMessageSize = 10_000,
        int $maxOutboundQueueSize = RawConnection::DEFAULT_MAX_QUEUE_SIZE,
    ): Router {
        $router = new Router($path);

        // Each connection gets its own bounded outbound queue and a single
        // writer fiber, so a peer that stops reading suspends only its own
        // writer instead of the fan-out walking the channel's subscribers.
        $handler = new WebSocketHandler(
            app(PusherServer::class),
            app(ApplicationProvider::class),
            $maxOutboundQueueSize,
        );

        // Bound buffered websocket messages at the parser level so an oversized
        // frame is rejected (close code 1009, MESSAGE_TOO_LARGE) while it is
        // still being buffered, rather than after the full message has been
        // assembled in memory. The limit is resolved per connection from the
        // application named in the upgrade request, so a client of a 10KB app
        // can no longer make the server buffer up to a 10MB app's limit before
        // Pusher\Server::message() rejects it. `$fallbackMessageSize` is only the
        // fallback for an upgrade whose app key does not resolve.
        $clientFactory = new ApplicationClientFactory(
            app(ApplicationProvider::class),
            $fallbackMessageSize,
        );

        // The fledge-fiber Websocket request handler performs the RFC 6455
        // handshake and, on success, upgrades the socket and hands the client
        // to our WebSocketHandler::handleClient() inside a dedicated fiber.
        $websocket = new Websocket(
            $socketServer,
            $logger,
            new Rfc6455Acceptor,
            $handler,
            clientFactory: $clientFactory,
        );

        $router->get('/app/{appKey}', $websocket);

        // Pusher-compatible HTTP REST API. Controllers resolve their own
        // dependencies (ApplicationProvider, ChannelManager) from the container.
        $router->post('/apps/{appId}/events', new EventsController);
        $router->post('/apps/{appId}/batch_events', new EventsBatchController);
        $router->get('/apps/{appId}/connections', new ConnectionsController);
        $router->get('/apps/{appId}/channels', new ChannelsController);
        $router->get('/apps/{appId}/channels/{channel}', new ChannelController);
        $router->get('/apps/{appId}/channels/{channel}/users', new ChannelUsersController);
        $router->post('/apps/{appId}/users/{userId}/terminate_connections', new UsersTerminateController);

        $router->get('/up', new HealthCheckController);

        return $router;
    }

    /**
     * Build a PSR logger for the fledge-fiber transport.
     *
     * The Resonate protocol layer logs through its own Contracts\Logger; the
     * fledge-fiber transport only needs a PSR logger for low-level transport
     * diagnostics, so a null logger keeps that noise out of the console.
     */
    protected static function logger(): LoggerInterface
    {
        return new NullLogger;
    }

    /**
     * Configure the TLS bind context for the server.
     *
     * @param  array<string, mixed>  $context
     */
    protected static function configureTls(array $context, ?string $hostname): ?BindContext
    {
        $context = array_filter($context, fn ($value) => $value !== null);

        $certificate = $context['local_cert'] ?? null;
        $key = $context['local_pk'] ?? null;

        if (! $certificate) {
            return null;
        }

        $tlsContext = (new ServerTlsContext)
            ->withDefaultCertificate(new Certificate(
                $certificate,
                $key ?: $certificate,
                $context['passphrase'] ?? null,
            ));

        if ($hostname !== null) {
            $tlsContext = $tlsContext->withPeerName($hostname);
        }

        return (new BindContext)->withTlsContext($tlsContext);
    }
}

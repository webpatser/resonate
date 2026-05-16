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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver;
use Webpatser\Resonate\Contracts\ApplicationProvider;
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
    ): HttpServer {
        if ($loop !== null) {
            EventLoop::setDriver($loop);
        }

        $logger = self::logger();

        // Enforce the configured `max_request_size` at the HTTP/1.1 + HTTP/2
        // driver level so an oversized body is rejected before it ever reaches
        // the controllers. Phase 5's audit caught this: the parameter used to
        // be accepted but never wired through.
        $socketServer = SocketHttpServer::createForDirectAccess(
            logger: $logger,
            httpDriverFactory: new DefaultHttpDriverFactory(
                logger: $logger,
                bodySizeLimit: $maxRequestSize,
            ),
        );

        $bindContext = self::configureTls($options['tls'] ?? [], $hostname);

        $socketServer->expose("{$host}:{$port}", $bindContext);

        $router = self::makeRouter($path, $socketServer, $logger);

        return new HttpServer($socketServer, $router, new DefaultErrorHandler);
    }

    /**
     * Build the Resonate router with the WebSocket and health check routes.
     */
    protected static function makeRouter(string $path, SocketHttpServer $socketServer, LoggerInterface $logger): Router
    {
        $router = new Router($path);

        $handler = new WebSocketHandler(
            app(PusherServer::class),
            app(ApplicationProvider::class),
        );

        // The fledge-fiber Websocket request handler performs the RFC 6455
        // handshake and, on success, upgrades the socket and hands the client
        // to our WebSocketHandler::handleClient() inside a dedicated fiber.
        $websocket = new Websocket(
            $socketServer,
            $logger,
            new Rfc6455Acceptor,
            $handler,
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

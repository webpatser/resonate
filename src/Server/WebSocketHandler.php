<?php

namespace Webpatser\Resonate\Server;

use Fledge\Async\Http\Server\Request;
use Fledge\Async\Http\Server\Response;
use Fledge\Async\WebSocket\Server\WebsocketClientHandler;
use Fledge\Async\WebSocket\WebsocketClient;
use Throwable;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Exceptions\InvalidApplication;
use Webpatser\Resonate\Loggers\Log;
use Webpatser\Resonate\Protocols\Pusher\Server;
use Webpatser\Resonate\Server\Concerns\ClosesConnections;
use Webpatser\Resonate\Server\Concerns\ResolvesApplicationKey;

/**
 * Bridges the fledge-fiber WebSocket transport to the Pusher protocol server.
 *
 * fledge-fiber invokes {@see handleClient()} once per upgraded connection. The
 * method runs a blocking receive loop (inside a fiber) for the lifetime of the
 * connection. This replaces Reverb's ReactPHP callback-driven message buffer.
 */
class WebSocketHandler implements WebsocketClientHandler
{
    use ClosesConnections;
    use ResolvesApplicationKey;

    /**
     * Create a new WebSocket handler instance.
     *
     * @param  int  $maxOutboundQueueSize  Frames one connection may queue before it is dropped.
     */
    public function __construct(
        protected Server $server,
        protected ApplicationProvider $applications,
        protected int $maxOutboundQueueSize = RawConnection::DEFAULT_MAX_QUEUE_SIZE,
    ) {
        //
    }

    /**
     * Handle a newly upgraded WebSocket connection.
     *
     * The route's {appKey} placeholder is made available by the router as a
     * request attribute. We resolve the application, wrap the fledge client in
     * a Resonate connection, hand it to the Pusher server, then pump messages
     * from the client until it disconnects.
     */
    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $appKey = $this->appKey($request);

        try {
            $application = $this->applications->findByKey((string) $appKey);
        } catch (InvalidApplication) {
            // Mirror Reverb's 4001 error frame for an unknown application.
            $this->closeWithError($client, 4001, 'Application does not exist');

            return;
        }

        $connection = new WebSocketConnection(
            new RawConnection($client, $this->maxOutboundQueueSize),
            $application,
            $request->getHeader('origin'),
        );

        // A rejected connection has already been sent its error frame and
        // terminated. Entering the receive loop here would keep serving it.
        if (! $this->server->open($connection)) {
            return;
        }

        try {
            while ($message = $client->receive()) {
                $this->server->message($connection, $message->buffer());
            }
        } catch (Throwable $e) {
            Log::error($e->getMessage());
        } finally {
            $this->server->close($connection);
        }
    }
}

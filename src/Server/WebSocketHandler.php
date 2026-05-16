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

    /**
     * Create a new WebSocket handler instance.
     */
    public function __construct(
        protected Server $server,
        protected ApplicationProvider $applications,
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
            new RawConnection($client),
            $application,
            $request->getHeader('origin'),
        );

        $this->server->open($connection);

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

    /**
     * Extract the {appKey} route parameter from the request.
     */
    protected function appKey(Request $request): ?string
    {
        if ($request->hasAttribute(Router::class)) {
            $arguments = $request->getAttribute(Router::class);

            if (isset($arguments['appKey'])) {
                return $arguments['appKey'];
            }
        }

        // Fallback: derive the key from the request path (/app/{appKey}).
        if (preg_match('#/app/([^/?]+)#', $request->getUri()->getPath(), $matches)) {
            return $matches[1];
        }

        return null;
    }
}

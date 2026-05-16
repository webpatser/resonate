<?php

namespace Webpatser\Resonate\Server\Concerns;

use Fledge\Async\WebSocket\WebsocketClient;
use Fledge\Async\WebSocket\WebsocketCloseCode;

/**
 * Helpers for closing fledge-fiber WebSocket clients.
 *
 * Ported from Reverb's ClosesConnections trait. Reverb sent a PSR-7 HTTP
 * response before closing the raw socket; here the connection has already been
 * upgraded to a WebSocket, so we send a Pusher protocol error frame as a text
 * message and then close the client.
 */
trait ClosesConnections
{
    /**
     * Send a Pusher protocol error frame to the client and close it.
     */
    protected function closeWithError(
        WebsocketClient $client,
        int $code,
        string $message,
        int $closeCode = WebsocketCloseCode::NORMAL_CLOSE,
    ): void {
        if ($client->isClosed()) {
            return;
        }

        try {
            $client->sendText(json_encode([
                'event' => 'pusher:error',
                'data' => json_encode([
                    'code' => $code,
                    'message' => $message,
                ]),
            ]));
        } finally {
            $client->close($closeCode, $message);
        }
    }
}

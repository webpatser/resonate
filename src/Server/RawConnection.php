<?php

namespace Webpatser\Resonate\Server;

use Fledge\Async\WebSocket\WebsocketClient;
use Fledge\Async\WebSocket\WebsocketCloseCode;
use Webpatser\Resonate\Contracts\WebSocketConnection;

/**
 * Raw transport-level connection.
 *
 * Adapts a fledge-fiber {@see WebsocketClient} to the Resonate
 * {@see WebSocketConnection} contract. This mirrors how Reverb separates the
 * raw socket connection from the protocol-level connection, but the underlying
 * transport here is fledge-fiber rather than ReactPHP/Ratchet.
 */
class RawConnection implements WebSocketConnection
{
    /**
     * Create a new raw connection instance.
     */
    public function __construct(protected WebsocketClient $client)
    {
        //
    }

    /**
     * Get the underlying fledge-fiber websocket client.
     */
    public function client(): WebsocketClient
    {
        return $this->client;
    }

    /**
     * Get the raw socket connection identifier.
     */
    public function id(): int|string
    {
        return $this->client->getId();
    }

    /**
     * Send a message to the connection.
     */
    public function send(mixed $message): void
    {
        if ($this->client->isClosed()) {
            return;
        }

        $this->client->sendText((string) $message);
    }

    /**
     * Send a low-level ping control frame to the connection.
     */
    public function ping(): void
    {
        if ($this->client->isClosed()) {
            return;
        }

        $this->client->ping();
    }

    /**
     * Close the connection.
     */
    public function close(mixed $message = null): void
    {
        if ($this->client->isClosed()) {
            return;
        }

        $this->client->close(
            WebsocketCloseCode::NORMAL_CLOSE,
            $message !== null ? (string) $message : '',
        );
    }

    /**
     * Determine whether the connection has been closed.
     */
    public function isClosed(): bool
    {
        return $this->client->isClosed();
    }
}

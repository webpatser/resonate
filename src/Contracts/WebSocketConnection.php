<?php

namespace Webpatser\Resonate\Contracts;

/**
 * The raw transport-level connection.
 *
 * In Resonate this is implemented by the fledge-fiber WebSocket transport
 * (see Webpatser\Resonate\Server\WebSocketConnection) rather than ReactPHP.
 */
interface WebSocketConnection
{
    /**
     * Get the raw socket connection identifier.
     */
    public function id(): int|string;

    /**
     * Send a message to the connection.
     */
    public function send(mixed $message): void;

    /**
     * Close the connection.
     */
    public function close(mixed $message = null): void;
}

<?php

namespace Webpatser\Resonate\Server;

use Fledge\Async\WebSocket\WebsocketClient;
use Laravel\Reverb\Connection;
use Webpatser\Resonate\Concerns\GeneratesIdentifiers;
use Webpatser\Resonate\Contracts\Connection as ConnectionContract;
use Webpatser\Resonate\Events\MessageSent;

/**
 * Protocol-level connection backed by the fledge-fiber WebSocket transport.
 *
 * This is the equivalent of Reverb's {@see Connection}: it
 * extends the abstract {@see ConnectionContract} and adapts a fledge-fiber
 * {@see WebsocketClient} (wrapped in a
 * {@see RawConnection}) to the Pusher protocol server.
 */
class WebSocketConnection extends ConnectionContract
{
    use GeneratesIdentifiers;

    /**
     * The normalized Pusher socket ID.
     */
    protected ?string $id = null;

    /**
     * Get the raw socket connection identifier.
     */
    public function identifier(): string
    {
        return (string) $this->connection->id();
    }

    /**
     * Get the normalized Pusher socket ID.
     */
    public function id(): string
    {
        if (! $this->id) {
            $this->id = $this->generateId();
        }

        return $this->id;
    }

    /**
     * Send a message to the connection.
     */
    public function send(string $message): void
    {
        $this->connection->send($message);

        MessageSent::dispatch($this, $message);
    }

    /**
     * Send a control frame to the connection.
     *
     * The fledge-fiber transport is a full RFC 6455 implementation and handles
     * ping/pong framing internally, so only the "ping" control frame maps to a
     * transport action. "pong" frames are answered automatically by fledge.
     */
    public function control(string $type = self::CONTROL_PING): void
    {
        if ($type === self::CONTROL_PING && method_exists($this->connection, 'ping')) {
            $this->connection->ping();
        }
    }

    /**
     * Get the remote address of the connected client.
     *
     * Guarded the same way as `control()`: the narrow
     * {@see \Webpatser\Resonate\Contracts\WebSocketConnection} contract has no
     * address accessor, so a transport that cannot report one keeps working.
     */
    public function remoteAddress(): ?string
    {
        if (! method_exists($this->connection, 'remoteAddress')) {
            return null;
        }

        return $this->connection->remoteAddress();
    }

    /**
     * Terminate the connection.
     */
    public function terminate(): void
    {
        $this->connection->close();
    }
}

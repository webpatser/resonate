<?php

namespace Webpatser\Resonate\Protocols\Pusher\Channels;

use Throwable;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Loggers\Log;
use Webpatser\Resonate\Protocols\Pusher\Concerns\SerializesChannels;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;

class Channel
{
    use SerializesChannels;

    /**
     * The channel connections.
     *
     * @var ChannelConnectionManager
     */
    protected $connections;

    /**
     * Create a new channel instance.
     */
    public function __construct(protected string $name)
    {
        $this->connections = app(ChannelConnectionManager::class)->for($this->name);
    }

    /**
     * Get the channel name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Get all connections for the channel.
     *
     * @return array<string, ChannelConnection>
     */
    public function connections(): array
    {
        return $this->connections->all();
    }

    /**
     * Find a connection.
     */
    public function find(Connection $connection): ?ChannelConnection
    {
        return $this->connections->find($connection);
    }

    /**
     * Find a connection by its ID.
     */
    public function findById(string $id): ?ChannelConnection
    {
        return $this->connections->findById($id);
    }

    /**
     * Subscribe to the given channel.
     */
    public function subscribe(Connection $connection, ?string $auth = null, ?string $data = null): void
    {
        $this->connections->add($connection, $data ? json_decode($data, associative: true, flags: JSON_THROW_ON_ERROR) : []);
    }

    /**
     * Unsubscribe from the given channel.
     */
    public function unsubscribe(Connection $connection): void
    {
        $this->connections->remove($connection);

        if ($this->connections->isEmpty()) {
            app(ChannelManager::class)->for($connection->app())->remove($this);
        }
    }

    /**
     * Determine if the connection is subscribed to the channel.
     */
    public function subscribed(Connection $connection): bool
    {
        return $this->connections->find($connection) !== null;
    }

    /**
     * Send a message to all connections subscribed to the channel.
     *
     * @param  array<string, mixed>  $payload
     */
    public function broadcast(array $payload, ?Connection $except = null): void
    {
        if ($except === null) {
            $this->broadcastToAll($payload);

            return;
        }

        $message = (string) json_encode($payload);

        Log::info('Broadcasting To', $this->name());
        Log::message($message);

        foreach ($this->connections() as $connection) {
            if ($except->id() === $connection->id()) {
                continue;
            }

            $this->sendTo($connection, $message);
        }
    }

    /**
     * Send a broadcast to all connections.
     *
     * @param  array<string, mixed>  $payload
     */
    public function broadcastToAll(array $payload): void
    {
        $message = (string) json_encode($payload);

        Log::info('Broadcasting To', $this->name());
        Log::message($message);

        foreach ($this->connections() as $connection) {
            $this->sendTo($connection, $message);
        }
    }

    /**
     * Send a message to a single subscriber, isolating its failures.
     *
     * A peer that drops between the loop's read and the write throws
     * `WebsocketClosedException` from deep inside the transport. Uncaught, that
     * aborted the whole fan-out, so every subscriber after the dead one in the
     * loop silently missed the event and the publisher got a misleading 4200
     * "Invalid message format" reply.
     */
    protected function sendTo(ChannelConnection $connection, string $message): void
    {
        try {
            $connection->send($message);
        } catch (Throwable $e) {
            Log::error('Failed to send to '.$connection->id().': '.$e->getMessage());
        }
    }

    /**
     * Broadcast a message triggered from an internal source.
     *
     * @param  array<string, mixed>  $payload
     */
    public function broadcastInternally(array $payload, ?Connection $except = null): void
    {
        $this->broadcast($payload, $except);
    }

    /**
     * Get the data associated with the channel.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [];
    }
}

<?php

namespace Webpatser\Resonate\Protocols\Pusher\Managers;

use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Protocols\Pusher\Channels\ChannelConnection;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelConnectionManager;

class ArrayChannelConnectionManager implements ChannelConnectionManager
{
    /**
     * The channel name.
     */
    protected string $name;

    /**
     * The underlying connections.
     *
     * @var array<string, ChannelConnection>
     */
    protected $connections = [];

    /**
     * Get a channel connection manager for the given channel name.
     */
    #[\Override]
    public function for(string $name): ChannelConnectionManager
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Add a connection.
     *
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    public function add(Connection $connection, array $data): void
    {
        $this->connections[$connection->id()] = new ChannelConnection($connection, $data);
    }

    /**
     * Remove a connection.
     */
    #[\Override]
    public function remove(Connection $connection): void
    {
        unset($this->connections[$connection->id()]);
    }

    /**
     * Find a connection.
     */
    #[\Override]
    public function find(Connection $connection): ?ChannelConnection
    {
        return $this->findById($connection->id());
    }

    /**
     * Find a connection by its ID.
     */
    #[\Override]
    public function findById(string $id): ?ChannelConnection
    {
        return $this->connections[$id] ?? null;
    }

    /**
     * Get all of the connections.
     *
     * @return array<string, ChannelConnection>
     */
    #[\Override]
    public function all(): array
    {
        return $this->connections;
    }

    /**
     * Determine whether any connections remain on the channel.
     */
    #[\Override]
    public function isEmpty(): bool
    {
        return empty($this->connections);
    }

    /**
     * Flush the channel connection manager.
     */
    #[\Override]
    public function flush(): void
    {
        $this->connections = [];
    }
}

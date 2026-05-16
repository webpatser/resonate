<?php

namespace Webpatser\Resonate\Protocols\Pusher\Contracts;

use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Protocols\Pusher\Channels\ChannelConnection;

interface ChannelConnectionManager
{
    /**
     * Get a channel connection manager for the given channel name.
     */
    public function for(string $name): ChannelConnectionManager;

    /**
     * Add a connection.
     */
    public function add(Connection $connection, array $data): void;

    /**
     * Remove a connection.
     */
    public function remove(Connection $connection): void;

    /**
     * Find a connection.
     */
    public function find(Connection $connection): ?ChannelConnection;

    /**
     * Find a connection by its ID.
     */
    public function findById(string $id): ?ChannelConnection;

    /**
     * Get all of the connections.
     *
     * @return array<string, ChannelConnection>
     */
    public function all(): array;

    /**
     * Determine whether any connections remain on the channel.
     */
    public function isEmpty(): bool;

    /**
     * Flush the channel connection manager.
     */
    public function flush(): void;
}

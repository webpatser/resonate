<?php

namespace Webpatser\Resonate\Protocols\Pusher\Contracts;

use Webpatser\Resonate\Application;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;
use Webpatser\Resonate\Protocols\Pusher\Channels\ChannelConnection;

interface ChannelManager
{
    /**
     * Get the application instance.
     */
    public function app(): ?Application;

    /**
     * The application the channel manager should be scoped to.
     */
    public function for(Application $application): ChannelManager;

    /**
     * Get all the channels.
     *
     * @return array<string, Channel>
     */
    public function all(): array;

    /**
     * Determine whether the given channel exists.
     */
    public function exists(string $channel): bool;

    /**
     * Find the given channel.
     */
    public function find(string $channel): ?Channel;

    /**
     * Find the given channel or create it if it doesn't exist.
     */
    public function findOrCreate(string $channel): Channel;

    /**
     * Get all the connections for the given channels.
     *
     * @return array<string, ChannelConnection>
     */
    public function connections(?string $channel = null): array;

    /**
     * Find a single connection by its socket id.
     */
    public function findConnection(string $socketId): ?ChannelConnection;

    /**
     * Register an open connection for the current application.
     */
    public function addConnection(Connection $connection): void;

    /**
     * Remove an open connection from the current application.
     */
    public function removeConnection(Connection $connection): void;

    /**
     * Get every open connection for the current application, keyed by socket id.
     *
     * Unlike connections(), this is not derived from channel membership, so a
     * connection that never subscribed to anything is still listed.
     *
     * @return array<string, Connection>
     */
    public function openConnections(): array;

    /**
     * Get the number of open connections for the current application.
     */
    public function connectionCount(): int;

    /**
     * Unsubscribe from all channels.
     */
    public function unsubscribeFromAll(Connection $connection): void;

    /**
     * Remove the given channel.
     */
    public function remove(Channel $channel): void;

    /**
     * Flush the channel manager repository.
     */
    public function flush(): void;
}

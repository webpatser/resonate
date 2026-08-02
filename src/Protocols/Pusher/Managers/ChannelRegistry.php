<?php

namespace Webpatser\Resonate\Protocols\Pusher\Managers;

use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;

/**
 * The shared channel and open-connection state for every application.
 *
 * This exists so that {@see ArrayChannelManager} can be an immutable per-application
 * view rather than a singleton carrying a mutable scope. The registry is the one
 * shared object; each view holds a reference to it plus the application it is
 * bound to, so a view handed to one fiber can never be re-scoped by another.
 */
class ChannelRegistry
{
    /**
     * Channels keyed by application ID and then by channel name.
     *
     * @var array<string, array<string, Channel>>
     */
    protected array $channels = [];

    /**
     * Open connections keyed by application ID and then by socket ID.
     *
     * This is the single source of truth for who is connected. Channel
     * membership is not: a connection that completes the handshake and never
     * sends `pusher:subscribe` belongs to no channel, so deriving the open set
     * from channels made it invisible to the ping and prune jobs while it still
     * held a slot against `max_connections` and the transport's global limit.
     * Protocol-level pings do not save it either, because browsers answer those
     * automatically without the server learning anything about liveness.
     *
     * @var array<string, array<string, Connection>>
     */
    protected array $connections = [];

    /**
     * Get every channel for the given application.
     *
     * @return array<string, Channel>
     */
    public function channels(string $applicationId): array
    {
        return $this->channels[$applicationId] ?? [];
    }

    /**
     * Get a single channel for the given application.
     */
    public function channel(string $applicationId, string $channel): ?Channel
    {
        return $this->channels[$applicationId][$channel] ?? null;
    }

    /**
     * Determine whether the given channel exists for the application.
     */
    public function has(string $applicationId, string $channel): bool
    {
        return isset($this->channels[$applicationId][$channel]);
    }

    /**
     * Store a channel for the given application.
     */
    public function put(string $applicationId, Channel $channel): void
    {
        $this->channels[$applicationId][$channel->name()] = $channel;
    }

    /**
     * Remove a channel from the given application.
     */
    public function forget(string $applicationId, string $channel): void
    {
        unset($this->channels[$applicationId][$channel]);
    }

    /**
     * Register an open connection for the given application.
     */
    public function addConnection(string $applicationId, Connection $connection): void
    {
        $this->connections[$applicationId][$connection->id()] = $connection;
    }

    /**
     * Remove an open connection from the given application.
     *
     * Keying by socket ID makes this idempotent: a connection pruned by the
     * stale sweep and then closed by the transport is removed once, so the
     * count can no longer drift below the live total.
     */
    public function removeConnection(string $applicationId, Connection $connection): void
    {
        unset($this->connections[$applicationId][$connection->id()]);
    }

    /**
     * Get every open connection for the given application.
     *
     * @return array<string, Connection>
     */
    public function openConnections(string $applicationId): array
    {
        return $this->connections[$applicationId] ?? [];
    }

    /**
     * Get the open-connection count for the given application.
     */
    public function count(string $applicationId): int
    {
        return count($this->openConnections($applicationId));
    }

    /**
     * Reset the channels and open connections for the given application IDs.
     *
     * @param  iterable<string>  $applicationIds
     */
    public function flush(iterable $applicationIds): void
    {
        foreach ($applicationIds as $applicationId) {
            $this->channels[$applicationId] = [];
            $this->connections[$applicationId] = [];
        }
    }
}

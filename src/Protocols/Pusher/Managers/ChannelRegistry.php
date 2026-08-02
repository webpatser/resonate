<?php

namespace Webpatser\Resonate\Protocols\Pusher\Managers;

use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;

/**
 * The shared channel and connection-count state for every application.
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
     * Open-connection counts keyed by application ID.
     *
     * Tracked independently of channel subscription so the connection limit
     * covers connections that complete the handshake but never subscribe.
     *
     * @var array<string, int>
     */
    protected array $connectionCounts = [];

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
     * Increment the open-connection count for the given application.
     */
    public function increment(string $applicationId): void
    {
        $this->connectionCounts[$applicationId] = $this->count($applicationId) + 1;
    }

    /**
     * Decrement the open-connection count for the given application.
     */
    public function decrement(string $applicationId): void
    {
        $this->connectionCounts[$applicationId] = max(0, $this->count($applicationId) - 1);
    }

    /**
     * Get the open-connection count for the given application.
     */
    public function count(string $applicationId): int
    {
        return $this->connectionCounts[$applicationId] ?? 0;
    }

    /**
     * Reset the channels and counts for the given application IDs.
     *
     * @param  iterable<string>  $applicationIds
     */
    public function flush(iterable $applicationIds): void
    {
        foreach ($applicationIds as $applicationId) {
            $this->channels[$applicationId] = [];
            $this->connectionCounts[$applicationId] = 0;
        }
    }
}

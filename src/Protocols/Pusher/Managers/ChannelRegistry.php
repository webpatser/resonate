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
     * The channel names each socket has joined, keyed by application ID and then by socket ID.
     *
     * Lets a closing connection leave only its own channels instead of every
     * channel of the app. An entry may outlive its channel (one removed while
     * the socket was still listed); visiting a channel the socket is not in is
     * harmless, so stale entries are dropped lazily rather than hunted down.
     *
     * @var array<string, array<string, array<string, true>>>
     */
    protected array $joined = [];

    /**
     * Registered channels with no join recorded since they were stored, keyed by application ID.
     *
     * A channel that loses its last member removes itself, so these are the
     * only empty channels there are. The full walk this replaces reaped them
     * on every disconnect, so leaving everything still visits them.
     *
     * This is also what keeps leaving everything correct when joins are not
     * recorded at all (a bound manager that wraps this one, or a manager with
     * a registry of its own): every channel then stays in this set, so
     * leaving everything is the full walk again.
     *
     * @var array<string, array<string, true>>
     */
    protected array $vacant = [];

    /**
     * The position each channel was stored at, keyed by application ID and then by channel name.
     *
     * @var array<string, array<string, int>>
     */
    protected array $positions = [];

    /**
     * The last position handed out.
     */
    protected int $position = 0;

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
        $name = $channel->name();

        if (! isset($this->channels[$applicationId][$name])) {
            $this->positions[$applicationId][$name] = ++$this->position;
        }

        $this->channels[$applicationId][$name] = $channel;

        $this->vacant[$applicationId][$name] = true;
    }

    /**
     * Remove a channel from the given application.
     */
    public function forget(string $applicationId, string $channel): void
    {
        unset(
            $this->channels[$applicationId][$channel],
            $this->vacant[$applicationId][$channel],
            $this->positions[$applicationId][$channel],
        );
    }

    /**
     * Record that the socket joined the given channel.
     */
    public function join(string $applicationId, string $socketId, Channel $channel): void
    {
        $name = $channel->name();

        $this->joined[$applicationId][$socketId][$name] = true;

        if (($this->channels[$applicationId][$name] ?? null) === $channel) {
            unset($this->vacant[$applicationId][$name]);
        }
    }

    /**
     * Record that the socket left the given channel.
     *
     * Leaving an instance that is no longer registered keeps the entry, since
     * the socket may be in the channel registered under that name now.
     */
    public function leave(string $applicationId, string $socketId, Channel $channel): void
    {
        $name = $channel->name();

        if (($this->channels[$applicationId][$name] ?? $channel) !== $channel) {
            return;
        }

        unset($this->joined[$applicationId][$socketId][$name]);

        if (($this->joined[$applicationId][$socketId] ?? null) === []) {
            unset($this->joined[$applicationId][$socketId]);
        }
    }

    /**
     * Get the registered channels under the names the socket is recorded as having joined.
     *
     * A name may now hold an instance the socket is not in (removed and
     * recreated), so callers check membership on each channel returned.
     *
     * @return array<string, Channel>
     */
    public function joinedChannels(string $applicationId, string $socketId): array
    {
        $channels = [];

        foreach ($this->joined[$applicationId][$socketId] ?? [] as $name => $_) {
            if (isset($this->channels[$applicationId][$name])) {
                $channels[$name] = $this->channels[$applicationId][$name];
            }
        }

        return $channels;
    }

    /**
     * Get the registered channels leaving everything must visit for the socket, in registry order.
     *
     * Those are the channels it joined plus the vacant ones: exactly the
     * channels on which a walk over every channel had any effect.
     *
     * @return array<string, Channel>
     */
    public function channelsToLeave(string $applicationId, string $socketId): array
    {
        $names = ($this->joined[$applicationId][$socketId] ?? []) + ($this->vacant[$applicationId] ?? []);

        $channels = [];

        foreach ($names as $name => $_) {
            if (isset($this->channels[$applicationId][$name])) {
                $channels[$name] = $this->channels[$applicationId][$name];

                continue;
            }

            unset($this->joined[$applicationId][$socketId][$name]);
        }

        if (($this->joined[$applicationId][$socketId] ?? null) === []) {
            unset($this->joined[$applicationId][$socketId]);
        }

        $positions = $this->positions[$applicationId] ?? [];

        uksort($channels, fn ($a, $b) => ($positions[$a] ?? 0) <=> ($positions[$b] ?? 0));

        return $channels;
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
            $this->joined[$applicationId] = [];
            $this->vacant[$applicationId] = [];
            $this->positions[$applicationId] = [];
        }
    }
}

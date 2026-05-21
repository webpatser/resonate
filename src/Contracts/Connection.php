<?php

namespace Webpatser\Resonate\Contracts;

use Webpatser\Resonate\Application;

abstract class Connection
{
    /**
     * The "ping" control frame type.
     */
    public const CONTROL_PING = 'ping';

    /**
     * The "pong" control frame type.
     */
    public const CONTROL_PONG = 'pong';

    /**
     * The last time the connection was seen.
     */
    protected ?int $lastSeenAt;

    /**
     * Stores the ping state of the connection.
     */
    protected $hasBeenPinged = false;

    /**
     * Indicates if the connection uses control frames.
     */
    protected $usesControlFrames = false;

    /**
     * Plugin-owned per-connection state.
     *
     * This is per-socket state that lives for the whole connection, unlike
     * presence `channel_data` which lives on a per-channel ChannelConnection
     * and is lost on unsubscribe. Plugins should namespace keys (e.g. `mg.*`)
     * to avoid collisions when several plugins are loaded.
     *
     * @var array<string, mixed>
     */
    protected array $pluginState = [];

    /**
     * Create a new connection instance.
     */
    public function __construct(protected WebSocketConnection $connection, protected Application $application, protected ?string $origin)
    {
        $this->lastSeenAt = time();
    }

    /**
     * Get the raw socket connection identifier.
     */
    abstract public function identifier(): string;

    /**
     * Get the normalized socket ID.
     */
    abstract public function id(): string;

    /**
     * Send a message to the connection.
     */
    abstract public function send(string $message): void;

    /**
     * Send a control frame to the connection.
     */
    abstract public function control(string $type = self::CONTROL_PING): void;

    /**
     * Terminate a connection.
     */
    abstract public function terminate(): void;

    /**
     * Get the application the connection belongs to.
     */
    public function app(): Application
    {
        return $this->application;
    }

    /**
     * Get the origin of the connection.
     */
    public function origin(): ?string
    {
        return $this->origin;
    }

    /**
     * Mark the connection as pinged.
     */
    public function ping(): void
    {
        $this->hasBeenPinged = true;
    }

    /**
     * Mark the connection as ponged.
     */
    public function pong(): void
    {
        $this->hasBeenPinged = false;
    }

    /**
     * Get the last time the connection was seen.
     */
    public function lastSeenAt(): ?int
    {
        return $this->lastSeenAt;
    }

    /**
     * Set the connection last seen at timestamp.
     */
    public function setLastSeenAt(int $time): Connection
    {
        $this->lastSeenAt = $time;

        return $this;
    }

    /**
     * Touch the connection last seen at timestamp.
     */
    public function touch(): Connection
    {
        $this->setLastSeenAt(time());
        $this->pong();

        return $this;
    }

    /**
     * Disconnect and unsubscribe from all channels.
     */
    public function disconnect(): void
    {
        $this->terminate();
    }

    /**
     * Determine whether the connection is still active.
     */
    public function isActive(): bool
    {
        return time() < $this->lastSeenAt + $this->app()->pingInterval();
    }

    /**
     * Determine whether the connection is inactive.
     */
    public function isInactive(): bool
    {
        return ! $this->isActive();
    }

    /**
     * Determine whether the connection is stale.
     */
    public function isStale(): bool
    {
        return $this->isInactive() && $this->hasBeenPinged;
    }

    /**
     * Determine whether the connection uses control frames.
     */
    public function usesControlFrames(): bool
    {
        return $this->usesControlFrames;
    }

    /**
     * Mark the connection as using control frames to track activity.
     */
    public function setUsesControlFrames(bool $usesControlFrames = true): Connection
    {
        $this->usesControlFrames = $usesControlFrames;

        return $this;
    }

    /**
     * Set a plugin-owned state value on the connection.
     */
    public function setState(string $key, mixed $value): Connection
    {
        $this->pluginState[$key] = $value;

        return $this;
    }

    /**
     * Get a plugin-owned state value, or the whole state bag when no key is given.
     */
    public function state(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->pluginState;
        }

        return $this->pluginState[$key] ?? $default;
    }

    /**
     * Determine whether a plugin-owned state key is set.
     */
    public function hasState(string $key): bool
    {
        return array_key_exists($key, $this->pluginState);
    }

    /**
     * Remove a plugin-owned state value from the connection.
     */
    public function forgetState(string $key): Connection
    {
        unset($this->pluginState[$key]);

        return $this;
    }
}

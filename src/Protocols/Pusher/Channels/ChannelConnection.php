<?php

namespace Webpatser\Resonate\Protocols\Pusher\Channels;

use Illuminate\Support\Arr;
use Webpatser\Resonate\Application;
use Webpatser\Resonate\Contracts\Connection;

/**
 * Wraps a connection together with the data it subscribed to a channel with.
 *
 * Any method not defined here is proxied to the underlying connection by __call().
 *
 * @method string identifier()
 * @method string id()
 * @method void control(string $type = Connection::CONTROL_PING)
 * @method void terminate()
 * @method Application app()
 * @method string|null origin()
 * @method void ping()
 * @method void pong()
 * @method int|null lastSeenAt()
 * @method Connection setLastSeenAt(int $time)
 * @method Connection touch()
 * @method void disconnect()
 * @method bool isActive()
 * @method bool isInactive()
 * @method bool isStale()
 * @method bool usesControlFrames()
 * @method Connection setUsesControlFrames(bool $usesControlFrames = true)
 * @method Connection setState(string $key, mixed $value)
 * @method mixed state(?string $key = null, mixed $default = null)
 * @method bool hasState(string $key)
 * @method Connection forgetState(string $key)
 */
class ChannelConnection
{
    /**
     * When the connection joined the channel, as a Unix timestamp with microseconds.
     *
     * Presence channels compare this across nodes to decide which of a user's
     * connections is the first one, and so which node announces the member.
     */
    protected float $subscribedAt;

    /**
     * Create a new channel connection instance.
     *
     * @param  array<string, mixed>  $data
     */
    public function __construct(protected Connection $connection, protected array $data = [])
    {
        $this->subscribedAt = microtime(true);
    }

    /**
     * Get when the connection joined the channel.
     */
    public function subscribedAt(): float
    {
        return $this->subscribedAt;
    }

    /**
     * Get the underlying connection.
     */
    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * Get the connection data.
     */
    public function data(?string $key = null): mixed
    {
        return $key ? Arr::get($this->data, $key) : $this->data;
    }

    /**
     * Send a message to the connection.
     */
    public function send(string $message): void
    {
        $this->connection->send($message);
    }

    /**
     * Proxy the given method to the underlying connection.
     *
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->connection->{$method}(...$parameters);
    }
}

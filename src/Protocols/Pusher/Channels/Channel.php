<?php

namespace Webpatser\Resonate\Protocols\Pusher\Channels;

use Throwable;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Loggers\Log;
use Webpatser\Resonate\Protocols\Pusher\Concerns\SerializesChannels;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Server\RawConnection;

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
     * Broadcasts held back from connections still being subscribed, keyed by socket id.
     *
     * @var array<string, array{holds: int, frames: array<int, string>, overflowed: bool}>
     */
    protected array $held = [];

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

        // Frames held for the old subscription are not owed to a subscribe
        // of the same socket that is still in flight and shares the hold.
        if (isset($this->held[$connection->id()])) {
            $this->held[$connection->id()]['frames'] = [];
        }

        if ($this->connections->isEmpty()) {
            app(ChannelManager::class)->for($connection->app())->remove($this);
        }
    }

    /**
     * Hold back broadcasts to the given connection until it is released.
     *
     * A subscription can suspend between the connection joining and its
     * confirmation: a presence channel asks the fleet about its members, and
     * a plugin's onSubscribe may wait on I/O. Broadcasts landing in that
     * window are buffered in arrival order instead of overtaking
     * `subscription_succeeded` or a plugin's replay. Holds nest, so two
     * subscribes of one socket in flight at once share a single buffer.
     *
     * The buffer is bounded like the outbound queue it drains into: a
     * connection that would outgrow it is terminated as soon as it does,
     * rather than being dropped by its queue when the buffer is released.
     */
    public function hold(Connection $connection): void
    {
        $this->held[$connection->id()] ??= ['holds' => 0, 'frames' => [], 'overflowed' => false];

        $this->held[$connection->id()]['holds']++;
    }

    /**
     * Release a hold, delivering the buffered broadcasts once the last one ends.
     *
     * A connection that has left the channel in the meantime is owed nothing,
     * so its buffer is dropped.
     */
    public function release(Connection $connection): void
    {
        $id = $connection->id();

        if (! isset($this->held[$id]) || --$this->held[$id]['holds'] > 0) {
            return;
        }

        ['frames' => $frames, 'overflowed' => $overflowed] = $this->held[$id];

        unset($this->held[$id]);

        $subscription = $this->connections->findById($id);

        if ($subscription === null || $overflowed) {
            return;
        }

        foreach ($frames as $frame) {
            $this->sendTo($subscription, $frame);
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
        if ($this->held !== []) {
            $id = $connection->id();

            if (isset($this->held[$id])) {
                $this->holdFrame($connection, $id, $message);

                return;
            }
        }

        try {
            $connection->send($message);
        } catch (Throwable $e) {
            Log::error('Failed to send to '.$connection->id().': '.$e->getMessage());
        }
    }

    /**
     * Buffer a frame for a held connection, terminating it once the buffer is full.
     */
    protected function holdFrame(ChannelConnection $connection, string $id, string $message): void
    {
        if ($this->held[$id]['overflowed']) {
            return;
        }

        $limit = (int) config('reverb.servers.reverb.max_outbound_queue_size', RawConnection::DEFAULT_MAX_QUEUE_SIZE);

        if ($limit <= 0 || count($this->held[$id]['frames']) < $limit) {
            $this->held[$id]['frames'][] = $message;

            return;
        }

        $this->held[$id]['frames'] = [];
        $this->held[$id]['overflowed'] = true;

        Log::error('Connection '.$id.' fell behind after '.$limit.' broadcasts held while subscribing to '.$this->name().'; terminating it');

        try {
            $connection->terminate();
        } catch (Throwable $e) {
            Log::error('Failed to terminate '.$id.': '.$e->getMessage());
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

<?php

namespace Webpatser\Resonate\Protocols\Pusher\Managers;

use Illuminate\Support\Arr;
use RuntimeException;
use Webpatser\Resonate\Application;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Events\ChannelCreated;
use Webpatser\Resonate\Events\ChannelRemoved;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;
use Webpatser\Resonate\Protocols\Pusher\Channels\ChannelBroker;
use Webpatser\Resonate\Protocols\Pusher\Channels\ChannelConnection;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager as ChannelManagerInterface;

/**
 * An immutable, per-application view over the shared {@see ChannelRegistry}.
 *
 * `for()` returns a NEW view rather than re-scoping this one. That matters
 * because the container binds one manager for the whole process while every
 * connection runs in its own fiber: when `for()` mutated a shared instance,
 * any suspension between scoping and a later read let another fiber re-scope
 * it underneath the first, so a request could read, count, or disconnect
 * another tenant's connections. Holding a view is now safe across suspension
 * points, because nothing can change what it points at.
 *
 * The container-bound instance is unscoped. Calling a scoped method on it
 * throws instead of silently inheriting whichever application happened to be
 * set last.
 */
class ArrayChannelManager implements ChannelManagerInterface
{
    public function __construct(
        protected ChannelRegistry $registry = new ChannelRegistry,
        protected ?Application $application = null,
    ) {
        //
    }

    /**
     * Get a view of the manager scoped to the given application.
     *
     * Deliberately `self` rather than `static`: a subclass is free to change
     * the constructor, so building one here would be unsafe. Subclasses that
     * need their own view type should override this method.
     */
    public function for(Application $application): ChannelManagerInterface
    {
        return new self($this->registry, $application);
    }

    /**
     * Get the application instance.
     */
    public function app(): ?Application
    {
        return $this->application;
    }

    /**
     * Get the ID of the application this view is scoped to.
     *
     * @throws RuntimeException When the view has no application.
     */
    protected function applicationId(): string
    {
        if ($this->application === null) {
            throw new RuntimeException(
                'The channel manager must be scoped to an application with for() before use.'
            );
        }

        return $this->application->id();
    }

    /**
     * Get all the channels.
     *
     * @return array<string, Channel>
     */
    public function all(): array
    {
        return $this->registry->channels($this->applicationId());
    }

    /**
     * Determine whether the given channel exists.
     */
    public function exists(string $channel): bool
    {
        return $this->registry->has($this->applicationId(), $channel);
    }

    /**
     * Find the given channel
     */
    public function find(string $channel): ?Channel
    {
        return $this->registry->channel($this->applicationId(), $channel);
    }

    /**
     * Find the given channel or create it if it doesn't exist.
     */
    public function findOrCreate(string $channelName): Channel
    {
        if ($channel = $this->find($channelName)) {
            return $channel;
        }

        $channel = ChannelBroker::create($channelName);

        $this->registry->put($this->applicationId(), $channel);

        ChannelCreated::dispatch($channel);

        return $channel;
    }

    /**
     * Get all of the connections for the given channels.
     *
     * @return array<string, ChannelConnection>
     */
    public function connections(?string $channel = null): array
    {
        $channels = Arr::wrap($this->channels($channel));

        $result = [];

        foreach ($channels as $ch) {
            foreach ($ch->connections() as $identifier => $connection) {
                // A socket subscribed to several channels appears once per channel under the
                // same identifier, and only some subscriptions carry the user identity, so an
                // identified wrapper must win over an anonymous one for the same socket.
                if (! isset($result[$identifier]) || ($result[$identifier]->data('user_id') === null && $connection->data('user_id') !== null)) {
                    $result[$identifier] = $connection;
                }
            }
        }

        return $result;
    }

    /**
     * Find a single connection by socket ID.
     */
    public function findConnection(string $socketId): ?ChannelConnection
    {
        foreach ($this->all() as $channel) {
            if ($connection = $channel->connections()[$socketId] ?? null) {
                return $connection;
            }
        }

        return null;
    }

    /**
     * Register an open connection for the current application.
     */
    public function addConnection(Connection $connection): void
    {
        $this->registry->addConnection($this->applicationId(), $connection);
    }

    /**
     * Remove an open connection from the current application.
     */
    public function removeConnection(Connection $connection): void
    {
        $this->registry->removeConnection($this->applicationId(), $connection);
    }

    /**
     * Get every open connection for the current application, keyed by socket ID.
     *
     * Unlike {@see connections()} this does not derive its answer from channel
     * membership, so a connection that never subscribed is still listed.
     *
     * @return array<string, Connection>
     */
    public function openConnections(): array
    {
        return $this->registry->openConnections($this->applicationId());
    }

    /**
     * Get the number of open connections for the current application.
     */
    public function connectionCount(): int
    {
        return $this->registry->count($this->applicationId());
    }

    /**
     * Unsubscribe from all channels.
     */
    public function unsubscribeFromAll(Connection $connection): void
    {
        foreach ($this->all() as $channel) {
            $channel->unsubscribe($connection);
        }
    }

    /**
     * Remove the given channel.
     */
    public function remove(Channel $channel): void
    {
        $this->registry->forget($this->applicationId(), $channel->name());

        ChannelRemoved::dispatch($channel);
    }

    /**
     * Get the given channel.
     */
    public function channel(string $channel): ?Channel
    {
        return $this->find($channel);
    }

    /**
     * Get the channels for the application.
     *
     * @return Channel|array<string, Channel>|null
     */
    public function channels(?string $channel = null): Channel|array|null
    {
        if (isset($channel)) {
            return $this->find($channel);
        }

        return $this->all();
    }

    /**
     * Flush the channel manager repository.
     */
    public function flush(): void
    {
        $this->registry->flush(
            app(ApplicationProvider::class)
                ->all()
                ->map(fn (Application $application) => $application->id())
        );
    }
}

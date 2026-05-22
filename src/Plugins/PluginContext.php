<?php

namespace Webpatser\Resonate\Plugins;

use Illuminate\Support\Collection;
use Webpatser\Resonate\Application;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Exceptions\InvalidApplication;
use Webpatser\Resonate\Protocols\Pusher\Channels\ChannelConnection;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\EventDispatcher;

/**
 * The API surface handed to a {@see Contracts\ServerPlugin} at boot.
 *
 * It wraps Resonate's broadcast/connection primitives so a plugin can push to
 * connections and channels and forcibly end connections without reaching into
 * core internals.
 */
class PluginContext
{
    /**
     * Create a new plugin context.
     */
    public function __construct(protected ChannelManager $channels)
    {
        //
    }

    /**
     * Push a single event to one connection.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendTo(Connection $connection, string $event, array $data = []): void
    {
        $connection->send(json_encode([
            'event' => $event,
            'data' => json_encode($data),
        ]));
    }

    /**
     * Broadcast an event to every connection subscribed to a channel.
     *
     * Routed through {@see EventDispatcher::dispatch()} so that, with scaling
     * enabled, the message reaches connections on every Resonate node.
     *
     * `$app` may be an {@see Application}, an app id string, or null to use the
     * sole configured app - so a {@see Contracts\TickScheduler} callback, which
     * has no connection to derive an app from, can still broadcast.
     *
     * @param  array<string, mixed>  $data
     */
    public function broadcast(Application|string|null $app, string $channel, string $event, array $data = [], ?Connection $except = null): void
    {
        EventDispatcher::dispatch($this->resolveApplication($app), [
            'event' => $event,
            'channel' => $channel,
            'data' => $data,
        ], $except);
    }

    /**
     * Forcibly end a connection, optionally sending a final event first.
     *
     * Only reaches connections on the local node; cross-node termination needs
     * a pub/sub terminate envelope (see UsersTerminateController).
     */
    public function terminate(Connection $connection, ?string $event = null, array $data = []): void
    {
        if ($event !== null) {
            $this->sendTo($connection, $event, $data);
        }

        $connection->terminate();
    }

    /**
     * Remove a connection from a single channel on this node.
     *
     * A direct server-side action: unlike {@see terminate()} it leaves the
     * socket open and the connection's other subscriptions intact. It does not
     * itself emit `onUnsubscribe` - that hook reports client-driven
     * `pusher:unsubscribe` events, mirroring how `onSubscribe` fires only from
     * the protocol path.
     */
    public function unsubscribe(Connection $connection, string $channel): void
    {
        $this->channels->for($connection->app())->find($channel)?->unsubscribe($connection);
    }

    /**
     * Get the live connections subscribed to a channel on this node.
     *
     * `$app` accepts the same forms as {@see broadcast()}.
     *
     * @return array<string, ChannelConnection>
     */
    public function connectionsOn(Application|string|null $app, string $channel): array
    {
        return $this->channels->for($this->resolveApplication($app))->find($channel)?->connections() ?? [];
    }

    /**
     * Get every configured application.
     *
     * @return Collection<int, Application>
     */
    public function applications(): Collection
    {
        return $this->applicationProvider()->all();
    }

    /**
     * Resolve a single application.
     *
     * With an id, looks it up; without one, returns the sole configured app.
     * A multi-app server must always pass an id explicitly.
     *
     * @throws InvalidApplication
     */
    public function application(?string $id = null): Application
    {
        $provider = $this->applicationProvider();

        if ($id !== null) {
            return $provider->findById($id);
        }

        $all = $provider->all();

        if ($all->count() === 1) {
            return $all->first();
        }

        throw new InvalidApplication;
    }

    /**
     * Normalize the {@see Application}|string|null forms accepted by the
     * broadcast/connection helpers into a concrete {@see Application}.
     *
     * @throws InvalidApplication
     */
    protected function resolveApplication(Application|string|null $app): Application
    {
        return $app instanceof Application ? $app : $this->application($app);
    }

    /**
     * Resolve the application provider lazily.
     *
     * Resolved on demand rather than constructor-injected so the context can
     * be built before the application list is finalized (and so the test suite
     * can mutate app config before the provider snapshots it).
     */
    protected function applicationProvider(): ApplicationProvider
    {
        return app(ApplicationProvider::class);
    }
}

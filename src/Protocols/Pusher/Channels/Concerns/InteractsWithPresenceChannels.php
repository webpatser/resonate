<?php

namespace Webpatser\Resonate\Protocols\Pusher\Channels\Concerns;

use Throwable;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Protocols\Pusher\EventDispatcher;
use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\Protocols\Pusher\MetricType;

/**
 * Presence channel membership, announced once per user across the fleet.
 *
 * Member events go through the {@see EventDispatcher}, so with scaling on they
 * reach subscribers on every node. A user may hold connections on several
 * nodes at once, so before announcing, this node asks the fleet which of the
 * user's connections exist: `member_added` is only sent for the user's first
 * connection anywhere, and `member_removed` only once the last one has left.
 * When that question cannot be answered the event is sent anyway, because a
 * duplicate announcement is harmless to a client while a missing one leaves
 * a stale or absent member in its list.
 */
trait InteractsWithPresenceChannels
{
    use InteractsWithPrivateChannels;

    /**
     * Subscribe to the given channel.
     */
    public function subscribe(Connection $connection, ?string $auth = null, ?string $data = null): void
    {
        $this->verify($connection, $auth, $data);

        $userData = $data ? json_decode($data, associative: true, flags: JSON_THROW_ON_ERROR) : [];

        if ($this->userIsSubscribed($userData['user_id'] ?? null)) {
            parent::subscribe($connection, $auth, $data);

            return;
        }

        parent::subscribe($connection, $auth, $data);

        if (! $this->isFirstConnectionOfUser($connection, $userData['user_id'] ?? null)) {
            return;
        }

        EventDispatcher::dispatch(
            $connection->app(),
            [
                'event' => 'pusher_internal:member_added',
                'data' => json_encode((object) $userData),
                'channel' => $this->name(),
            ],
            $connection
        );
    }

    /**
     * Unsubscribe from the given channel.
     */
    public function unsubscribe(Connection $connection): void
    {
        $subscription = $this->connections->find($connection);

        parent::unsubscribe($connection);

        if (
            ! $subscription ||
            ! $subscription->data('user_id') ||
            $this->userIsSubscribed($subscription->data('user_id'))
        ) {
            return;
        }

        if (! $this->userHasLeftEveryNode($connection, $subscription->data('user_id'))) {
            return;
        }

        EventDispatcher::dispatch(
            $connection->app(),
            [
                'event' => 'pusher_internal:member_removed',
                'data' => json_encode(['user_id' => $subscription->data('user_id')]),
                'channel' => $this->name(),
            ],
            $connection
        );
    }

    /**
     * Get the data associated with the channel.
     *
     * A member subscribed without `user_info` is listed with an empty object,
     * so the hash always serializes as `{"id": {...}}` and never as `null`.
     *
     * @return array{presence: array{count: int, ids: array<int, mixed>, hash: array<array-key, mixed>}}
     */
    public function data(): array
    {
        $connections = collect($this->connections->all())
            ->map(fn ($connection) => $connection->data())
            ->unique('user_id');

        if ($connections->contains(fn ($connection) => ! isset($connection['user_id']))) {
            return [
                'presence' => [
                    'count' => 0,
                    'ids' => [],
                    'hash' => [],
                ],
            ];
        }

        return [
            'presence' => [
                'count' => $connections->count(),
                'ids' => $connections->map(fn ($connection) => $connection['user_id'])->values()->all(),
                'hash' => $connections->keyBy('user_id')
                    ->map(fn ($connection) => $connection['user_info'] ?? (object) [])
                    ->toArray(),
            ],
        ];
    }

    /**
     * Determine if the given user is subscribed to the channel.
     */
    protected function userIsSubscribed(?string $userId): bool
    {
        if (! $userId) {
            return false;
        }

        return collect($this->connections->all())->map(fn ($connection) => (string) $connection->data('user_id'))->contains($userId);
    }

    /**
     * Determine whether the given connection is its user's first on this channel fleet-wide.
     *
     * The earliest subscription wins, with the socket id settling a tie, so
     * every node reaches the same verdict for the same set of connections.
     */
    protected function isFirstConnectionOfUser(Connection $connection, mixed $userId): bool
    {
        if ($this->presenceIsLocal() || ! is_scalar($userId) || (string) $userId === '') {
            return true;
        }

        try {
            $connections = $this->userConnectionsAcrossNodes($connection, (string) $userId);
        } catch (Throwable) {
            return true;
        }

        usort($connections, fn (array $a, array $b) => ($a['subscribed_at'] <=> $b['subscribed_at']) ?: strcmp($a['id'], $b['id']));

        $first = $connections[0] ?? null;

        return $first === null || $first['id'] === $connection->id();
    }

    /**
     * Determine whether the given user no longer holds a connection on any node.
     */
    protected function userHasLeftEveryNode(Connection $connection, mixed $userId): bool
    {
        if ($this->presenceIsLocal() || ! is_scalar($userId)) {
            return true;
        }

        try {
            return $this->userConnectionsAcrossNodes($connection, (string) $userId) === [];
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Gather the connections the given user holds on this channel on every node.
     *
     * Replies arrive off the wire, so entries without a string socket id are
     * dropped, and an entry without a usable timestamp sorts last.
     *
     * @return array<int, array{id: string, subscribed_at: float}>
     */
    protected function userConnectionsAcrossNodes(Connection $connection, string $userId): array
    {
        $gathered = app(MetricsHandler::class)->gather(
            $connection->app(),
            MetricType::PRESENCE_CONNECTIONS->value,
            ['channel' => $this->name(), 'user_id' => $userId],
        );

        $connections = [];

        foreach ($gathered as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null)) {
                continue;
            }

            $subscribedAt = $entry['subscribed_at'] ?? null;

            $connections[] = [
                'id' => $entry['id'],
                'subscribed_at' => is_int($subscribedAt) || is_float($subscribedAt) ? (float) $subscribedAt : PHP_FLOAT_MAX,
            ];
        }

        return $connections;
    }

    /**
     * Determine whether this node runs without horizontal scaling.
     */
    protected function presenceIsLocal(): bool
    {
        return ! app()->bound(ServerProvider::class)
            || app(ServerProvider::class)->shouldNotPublishEvents();
    }
}

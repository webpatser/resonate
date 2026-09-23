<?php

namespace Webpatser\Resonate\Protocols\Pusher;

use Exception;
use Fiber;
use Fledge\Async\DeferredFuture;
use Fledge\Async\Future;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Plugins\PluginManager;
use Webpatser\Resonate\Protocols\Pusher\Channels\CacheChannel;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;
use Webpatser\Resonate\Protocols\Pusher\Concerns\InteractsWithChannelInformation;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\Exceptions\SubscriptionLimitExceeded;

/**
 * @phpstan-type SubscribeState array{calls: int, notifying: int, announced: bool, idle: ?DeferredFuture<null>, fibers: list<int>, epoch: int}
 */
class EventHandler
{
    use InteractsWithChannelInformation;

    /**
     * The connection state key holding, per channel name, the subscribe calls
     * in flight, the onSubscribe passes running, and whether the plugins
     * have been told about the subscription.
     */
    public const SUBSCRIBING = 'resonate.subscribing';

    /**
     * Create a new Pusher event instance.
     */
    public function __construct(
        protected ChannelManager $channels,
        protected PluginManager $plugins,
    ) {
        //
    }

    /**
     * Handle an incoming Pusher event.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(Connection $connection, string $event, array $payload = []): void
    {
        $event = Str::after($event, 'pusher:');

        match ($event) {
            'connection_established' => $this->acknowledge($connection),
            'subscribe' => $this->subscribe(
                $connection,
                $payload['channel'],
                $payload['auth'] ?? null,
                $payload['channel_data'] ?? null
            ),
            'unsubscribe' => $this->unsubscribe($connection, $payload['channel']),
            'ping' => $this->pong($connection),
            'pong' => $connection->touch(),
            default => throw new Exception('Unknown Pusher event: '.$event),
        };
    }

    /**
     * Acknowledge the connection.
     */
    public function acknowledge(Connection $connection): void
    {
        $this->send($connection, 'connection_established', [
            'socket_id' => $connection->id(),
            'activity_timeout' => $connection->app()->activityTimeout(),
        ]);
    }

    /**
     * Subscribe to the given channel.
     *
     * Both limits are checked before the channel is looked up, because
     * `findOrCreate()` allocates a Channel for any name it is handed: an
     * unchecked subscribe was an allocation primitive, and the eventual
     * disconnect then walked every channel it had created.
     *
     * @throws SubscriptionLimitExceeded
     */
    public function subscribe(Connection $connection, string $channel, ?string $auth = null, ?string $data = null): void
    {
        Validator::make([
            'channel' => $channel,
            'auth' => $auth,
            'channel_data' => $data,
        ], [
            'channel' => $this->channelNameRules(),
            'auth' => ['nullable', 'string'],
            'channel_data' => ['nullable', 'json'],
        ])->validate();

        $this->ensureWithinSubscriptionLimit($connection, $channel);

        $channel = $this->channels
            ->for($connection->app())
            ->findOrCreate($channel);

        // A presence channel may wait on the fleet after the connection joins,
        // and a plugin's onSubscribe may wait on I/O. Broadcasts landing in
        // either window are held until subscription_succeeded and the whole
        // onSubscribe pass are out, then delivered in arrival order.
        $channel->hold($connection);

        $call = $this->beginSubscribe($connection, $channel);

        try {
            $channel->subscribe($connection, $auth, $data);

            // Left while the subscribe was in flight, possibly subscribing
            // again since: nothing for this call to confirm.
            if (! $this->confirms($connection, $channel, $call)) {
                return;
            }

            $this->afterSubscribe($channel, $connection, $call);
        } finally {
            $this->endSubscribe($connection, $channel);

            $channel->release($connection);
        }
    }

    /**
     * Get the validation rules for a subscribed channel name.
     *
     * A configured length of 0 disables the check.
     *
     * @return array<int, string>
     */
    protected function channelNameRules(): array
    {
        $rules = ['nullable', 'string'];

        $length = (int) config('reverb.servers.reverb.max_channel_name_length', 255);

        if ($length > 0) {
            $rules[] = 'max:'.$length;
        }

        return $rules;
    }

    /**
     * Ensure the connection is within its subscription cap.
     *
     * Re-subscribing to a channel the connection is already in is idempotent
     * and never counts against the cap, so a client at the limit can still
     * refresh an existing subscription.
     *
     * @throws SubscriptionLimitExceeded
     */
    protected function ensureWithinSubscriptionLimit(Connection $connection, string $channel): void
    {
        $limit = (int) config('reverb.servers.reverb.max_subscriptions_per_connection', 250);

        if ($limit <= 0) {
            return;
        }

        $channels = $this->channels->for($connection->app());

        if ($channels->find($channel)?->findById($connection->id()) !== null) {
            return;
        }

        $subscriptions = 0;

        foreach ($channels->all() as $existing) {
            if ($existing->findById($connection->id()) === null) {
                continue;
            }

            if (++$subscriptions >= $limit) {
                throw new SubscriptionLimitExceeded;
            }
        }
    }

    /**
     * Carry out any actions that should be performed after a subscription.
     */
    protected function afterSubscribe(Channel $channel, Connection $connection, ?int $call = null): void
    {
        $data = $this->subscriptionData($channel, $connection);

        // The gather above may suspend: the connection may leave meanwhile,
        // and a subscribe issued after that leave confirms on its own.
        if (! $this->confirms($connection, $channel, $call)) {
            return;
        }

        $this->sendInternally($connection, 'subscription_succeeded', $data, $channel->name());

        match (true) {
            $channel instanceof CacheChannel => $this->sendCachedPayload($channel, $connection),
            default => null,
        };

        $this->beginNotifying($connection, $channel);

        try {
            $this->plugins->notifySubscribe($connection, $channel);
        } finally {
            $this->endNotifying($connection, $channel);
        }
    }

    /**
     * Get the data a new subscriber receives with `subscription_succeeded`.
     *
     * With scaling on, a presence channel's members are spread over every
     * node, so the member list is gathered fleet-wide. A failed gather falls
     * back to this node's members: a partial list beats a failed subscribe.
     *
     * @return array<string, mixed>
     */
    protected function subscriptionData(Channel $channel, Connection $connection): array
    {
        if (! $this->isPresenceChannel($channel) || $this->scalingDisabled()) {
            return $channel->data();
        }

        try {
            $gathered = app(MetricsHandler::class)->gather(
                $connection->app(),
                MetricType::PRESENCE_DATA->value,
                ['channel' => $channel->name()],
            );
        } catch (Throwable) {
            return $channel->data();
        }

        return is_array($gathered['presence'] ?? null)
            ? ['presence' => $gathered['presence']]
            : $channel->data();
    }

    /**
     * Determine whether this node runs without horizontal scaling.
     */
    protected function scalingDisabled(): bool
    {
        return ! app()->bound(ServerProvider::class)
            || app(ServerProvider::class)->shouldNotPublishEvents();
    }

    /**
     * Unsubscribe from the given channel.
     *
     * A connection that leaves before its subscribe reached the plugins was
     * never announced to them, so they are not told it left either. One that
     * leaves while a plugin's onSubscribe is still running waits for that
     * pass to return, so every plugin hears the join before the leave. A
     * plugin unsubscribing from inside its own onSubscribe does not wait.
     */
    public function unsubscribe(Connection $connection, string $channel): void
    {
        $channels = $this->channels->for($connection->app());

        while (($found = $channels->find($channel)) !== null
            && ($idle = $this->notifyingElsewhere($connection, $found)) !== null) {
            $idle->await();
        }

        if ($found === null) {
            return;
        }

        $entry = $this->subscribing($connection, $found);

        $announced = $entry === null || $entry['calls'] === 0 || $entry['announced'];

        $found->unsubscribe($connection);

        // A subscribe still in flight belonged to the subscription that just
        // ended; only a subscribe issued from here on may confirm.
        if (($entry = $this->subscribing($connection, $found)) !== null) {
            $entry['announced'] = false;
            $entry['epoch']++;

            $this->putSubscribing($connection, $found, $entry);
        }

        if ($announced) {
            $this->plugins->notifyUnsubscribe($connection, $found);
        }
    }

    /**
     * Record a subscribe call to the channel and return its token.
     *
     * The token is the subscription epoch the call started in. An unsubscribe
     * ends the epoch, so a call that started before it leaves the
     * confirmation and the plugin announcement to a call that started after.
     */
    protected function beginSubscribe(Connection $connection, Channel $channel): int
    {
        $entry = $this->subscribing($connection, $channel) ?? [
            'calls' => 0,
            'notifying' => 0,
            'announced' => $channel->subscribed($connection),
            'idle' => null,
            'fibers' => [],
            'epoch' => 0,
        ];

        $entry['calls']++;

        $this->putSubscribing($connection, $channel, $entry);

        return $entry['epoch'];
    }

    /**
     * Record that a subscribe call to the channel returned.
     */
    protected function endSubscribe(Connection $connection, Channel $channel): void
    {
        $entry = $this->subscribing($connection, $channel);

        if ($entry === null) {
            return;
        }

        $entry['calls']--;

        $this->putSubscribing($connection, $channel, $entry);
    }

    /**
     * Determine whether the subscribe call should confirm the subscription.
     */
    protected function confirms(Connection $connection, Channel $channel, ?int $call): bool
    {
        if (! $channel->subscribed($connection)) {
            return false;
        }

        if ($call === null) {
            return true;
        }

        $entry = $this->subscribing($connection, $channel);

        return $entry === null || $entry['epoch'] === $call;
    }

    /**
     * Record that the plugins are being told about the subscription.
     */
    protected function beginNotifying(Connection $connection, Channel $channel): void
    {
        $entry = $this->subscribing($connection, $channel) ?? [
            'calls' => 0,
            'notifying' => 0,
            'announced' => false,
            'idle' => null,
            'fibers' => [],
            'epoch' => 0,
        ];

        $entry['announced'] = true;
        $entry['notifying']++;
        $entry['fibers'][] = $this->fiberId();

        $this->putSubscribing($connection, $channel, $entry);
    }

    /**
     * Record that an onSubscribe pass returned, waking unsubscribes waiting on it.
     */
    protected function endNotifying(Connection $connection, Channel $channel): void
    {
        $entry = $this->subscribing($connection, $channel);

        if ($entry === null) {
            return;
        }

        $entry['notifying']--;

        $index = array_search($this->fiberId(), $entry['fibers'], true);

        if ($index !== false) {
            unset($entry['fibers'][$index]);

            $entry['fibers'] = array_values($entry['fibers']);
        }

        $idle = null;

        if ($entry['notifying'] <= 0) {
            [$idle, $entry['idle']] = [$entry['idle'], null];
        }

        $this->putSubscribing($connection, $channel, $entry);

        $idle?->complete();
    }

    /**
     * Get the future an unsubscribe waits on while another fiber runs the channel's onSubscribe pass.
     *
     * @return Future<null>|null
     */
    protected function notifyingElsewhere(Connection $connection, Channel $channel): ?Future
    {
        $entry = $this->subscribing($connection, $channel);

        if ($entry === null
            || $entry['notifying'] <= 0
            || in_array($this->fiberId(), $entry['fibers'], true)) {
            return null;
        }

        $entry['idle'] ??= new DeferredFuture;

        $this->putSubscribing($connection, $channel, $entry);

        return $entry['idle']->getFuture();
    }

    /**
     * Get the in-flight subscribe state for the channel.
     *
     * @return SubscribeState|null
     */
    protected function subscribing(Connection $connection, Channel $channel): ?array
    {
        $subscribing = $connection->state(self::SUBSCRIBING, []);

        if (! is_array($subscribing) || ! is_array($subscribing[$channel->name()] ?? null)) {
            return null;
        }

        /** @var SubscribeState */
        return $subscribing[$channel->name()];
    }

    /**
     * Store the in-flight subscribe state for the channel, dropping it once nothing is in flight.
     *
     * @param  SubscribeState  $entry
     */
    protected function putSubscribing(Connection $connection, Channel $channel, array $entry): void
    {
        $subscribing = $connection->state(self::SUBSCRIBING, []);

        if (! is_array($subscribing)) {
            $subscribing = [];
        }

        if ($entry['calls'] <= 0 && $entry['notifying'] <= 0) {
            unset($subscribing[$channel->name()]);
        } else {
            $subscribing[$channel->name()] = $entry;
        }

        $subscribing === []
            ? $connection->forgetState(self::SUBSCRIBING)
            : $connection->setState(self::SUBSCRIBING, $subscribing);
    }

    /**
     * Get an identifier for the running fiber, 0 outside any fiber.
     */
    protected function fiberId(): int
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? 0 : spl_object_id($fiber);
    }

    /**
     * Send the cached payload for the given channel.
     */
    protected function sendCachedPayload(CacheChannel $channel, Connection $connection): void
    {
        if ($channel->hasCachedPayload()) {
            $connection->send(
                (string) json_encode($channel->cachedPayload())
            );

            return;
        }

        $this->send($connection, 'cache_miss', channel: $channel->name());
    }

    /**
     * Respond to a ping on the given connection.
     */
    public function pong(Connection $connection): void
    {
        static::send($connection, 'pong');
    }

    /**
     * Send a ping to the given connection.
     */
    public function ping(Connection $connection): void
    {
        $connection->usesControlFrames()
            ? $connection->control()
            : static::send($connection, 'ping');

        $connection->ping();
    }

    /**
     * Send a response to the given connection.
     *
     * @param  array<string, mixed>  $data
     */
    public function send(Connection $connection, string $event, array $data = [], ?string $channel = null): void
    {
        $connection->send(
            (string) static::formatPayload($event, $data, $channel)
        );
    }

    /**
     * Send an internal response to the given connection.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendInternally(Connection $connection, string $event, array $data = [], ?string $channel = null): void
    {
        $connection->send(
            (string) static::formatInternalPayload($event, $data, $channel)
        );
    }

    /**
     * Format the payload for the given event.
     *
     * @param  array<string, mixed>  $data
     */
    public function formatPayload(string $event, array $data = [], ?string $channel = null, string $prefix = 'pusher:'): string|false
    {
        return json_encode(
            array_filter([
                'event' => $prefix.$event,
                'data' => empty($data) ? null : json_encode($data),
                'channel' => $channel,
            ])
        );
    }

    /**
     * Format the internal payload for the given event.
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $channel
     */
    public function formatInternalPayload(string $event, array $data = [], $channel = null): string|false
    {
        return json_encode(
            array_filter([
                'event' => 'pusher_internal:'.$event,
                'data' => json_encode((object) $data),
                'channel' => $channel,
            ])
        );
    }
}

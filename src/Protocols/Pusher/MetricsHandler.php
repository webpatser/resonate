<?php

namespace Webpatser\Resonate\Protocols\Pusher;

use Fledge\Async\DeferredFuture;
use Illuminate\Support\Str;
use Revolt\EventLoop;
use Webpatser\Resonate\Application;
use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Protocols\Pusher\Channels\ChannelConnection;
use Webpatser\Resonate\Protocols\Pusher\Concerns\InteractsWithChannelInformation;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;

/**
 * Gathers channel and connection metrics for the Pusher REST API.
 *
 * Reverb's MetricsHandler is Promise-based: it fans a request out over Redis
 * pub/sub, collects sibling replies into `PendingMetric` value objects, and
 * resolves a `React\Promise` once every subscriber has answered (or a 10s
 * timeout fires). Resonate adapts that to fibers and a pure-JSON envelope:
 *
 *  - {@see gather()} keeps its `(Application, string, array): array` signature.
 *    When scaling is off it reads the local {@see ChannelManager} and returns
 *    immediately, identical to the Phase 3 behaviour.
 *  - When scaling is on, `gather()` publishes a `metrics` request envelope and
 *    awaits a {@see DeferredFuture} that is completed the moment every
 *    expected sibling reply has landed, or when the collection window timer
 *    fires, whichever comes first. The event loop keeps running, only this
 *    fiber parks. Sibling replies arrive on the pub/sub channel and are routed
 *    here through {@see publish()}, which buffers them keyed by request id.
 *    The buffered replies are then merged with this node's own local metrics.
 *  - {@see publish()} is the responder side too: a `metrics` *request* from a
 *    *sibling* makes this node gather its local metrics and publish a
 *    `metrics` *reply*.
 *
 * Nothing here is PHP-`serialize()`d; every envelope field is JSON-native.
 * There is no `PendingMetric` object: a plain array keyed by request id is
 * enough for correlation.
 *
 * Two properties of Redis pub/sub drive the design here:
 *
 *  - A node receives its own publications. The publisher and the subscriber
 *    are separate connections, so the request envelope this node sends comes
 *    straight back to its own subscriber. Without the {@see nodeId()} stamp
 *    carried in every request, the requesting node answered itself, buffered
 *    that reply, and then appended `local()` a second time, so `mergeChannel()`
 *    summed this node's `user_count` and `subscription_count` twice. Two nodes
 *    with five users each reported fifteen. The responder branch now drops any
 *    request carrying our own node id, and the explicit local append stays.
 *  - `PUBLISH` returns the number of subscribers that received the message.
 *    That is the expected reply count (minus our own subscriber), obtained
 *    with no extra round-trip and scoped to exactly the envelope just sent.
 */
class MetricsHandler
{
    use InteractsWithChannelInformation;

    /**
     * This node's identity, stamped into every request envelope it publishes.
     *
     * Random per process rather than derived from the host or pid: a container
     * host name is not unique across a fleet and pids are recycled, while a
     * collision here would make one node ignore another's requests.
     */
    protected string $nodeId;

    /**
     * In-flight metric requests, keyed by request id.
     *
     * `expected` is null until `PUBLISH` has reported the subscriber count,
     * which keeps a reply that arrives while the publish is still in flight
     * from completing the request early.
     *
     * @var array<string, array{expected: int|null, sets: array<int, array<string|int, mixed>>, deferred: DeferredFuture<null>}>
     */
    protected array $pending = [];

    /**
     * Create an instance of the metrics handler.
     *
     * @param  float  $collectionWindow  Upper bound, in seconds, on the wait for sibling replies.
     */
    public function __construct(protected ChannelManager $channels, protected float $collectionWindow = 1.0)
    {
        $this->nodeId = Str::random(20);
    }

    /**
     * Get this node's identity.
     */
    public function nodeId(): string
    {
        return $this->nodeId;
    }

    /**
     * Gather the metrics for the given type.
     *
     * When scaling is enabled this fans the request out to sibling nodes and
     * merges their replies with the local result; otherwise it returns the
     * local result directly.
     *
     * @param  array<string, mixed>  $options
     * @return array<string|int, mixed>
     */
    public function gather(Application $application, string $type, array $options = []): array
    {
        $metricType = MetricType::from($type);

        if ($this->shouldNotPublishEvents()) {
            return $this->local($application, $metricType, $options);
        }

        return $this->gatherFromSubscribers($application, $metricType, $options);
    }

    /**
     * Gather the metrics for the given type from the local channel manager.
     *
     * @param  array<string, mixed>  $options
     * @return array<string|int, mixed>
     */
    public function local(Application $application, MetricType $type, array $options = []): array
    {
        return match ($type) {
            MetricType::CHANNEL => $this->channel($application, $options),
            MetricType::CHANNELS => $this->channelsMetric($application, $options),
            MetricType::CHANNEL_USERS => $this->channelUsers($application, $options),
            MetricType::CONNECTIONS => $this->connections($application),
        };
    }

    /**
     * Publish a metrics envelope routed here by the pub/sub message handler.
     *
     * Two envelope shapes flow through here:
     *  - a *request* (`payload.type` + `payload.options` set): unless it is
     *    this node's own request coming back off the pub/sub channel, this
     *    node gathers its local metrics for the requested type and publishes a
     *    *reply* envelope back onto the pub/sub channel.
     *  - a *reply* (`payload.metrics` set): if this node is currently
     *    awaiting the matching request id, the metrics are appended to that
     *    request's buffer, which may complete the request immediately.
     *
     * @param  array{application: Application, payload: array<string, mixed>}  $envelope
     */
    public function publish(array $envelope): void
    {
        $application = $envelope['application'];
        $payload = $envelope['payload'];
        $key = $payload['key'] ?? null;

        if (! is_string($key)) {
            return;
        }

        // A reply for a request this node is awaiting.
        if (array_key_exists('metrics', $payload)) {
            $this->recordReply($key, $payload['metrics']);

            return;
        }

        if (! isset($payload['type'])) {
            return;
        }

        // Our own request, delivered back to us by our own subscriber. The
        // local metrics are appended directly in gatherFromSubscribers(), so
        // answering here would count this node twice.
        if (($payload['node'] ?? null) === $this->nodeId) {
            return;
        }

        // A request from a sibling node: answer with our local metrics.
        app(PubSubProvider::class)->publish([
            'type' => 'metrics',
            'application' => $application->id(),
            'payload' => [
                'key' => $key,
                'node' => $this->nodeId,
                'metrics' => $this->local(
                    $application,
                    MetricType::from($payload['type']),
                    $payload['options'] ?? []
                ),
            ],
        ]);
    }

    /**
     * Gather metrics from all sibling subscribers and merge with the local set.
     *
     * @param  array<string, mixed>  $options
     * @return array<string|int, mixed>
     */
    protected function gatherFromSubscribers(Application $application, MetricType $type, array $options): array
    {
        $requestId = Str::random(10);

        /** @var DeferredFuture<null> $deferred */
        $deferred = new DeferredFuture;

        $this->pending[$requestId] = [
            'expected' => null,
            'sets' => [],
            'deferred' => $deferred,
        ];

        // The window is a plain referenced timer rather than a
        // `TimeoutCancellation`, which unreferences its watcher: awaiting on
        // one is a deadlock whenever nothing else is holding the loop open.
        // Completing (rather than cancelling) also means a node that never
        // answers costs the window once and still returns what did arrive.
        $timeout = EventLoop::delay($this->collectionWindow, fn () => $this->complete($requestId));

        try {
            $receivers = app(PubSubProvider::class)->publish([
                'type' => 'metrics',
                'application' => $application->id(),
                'payload' => [
                    'key' => $requestId,
                    'node' => $this->nodeId,
                    'type' => $type->value,
                    'options' => $options,
                ],
            ]);

            // Every subscriber that received the envelope owes a reply, except
            // our own, which drops the request on the node id above.
            $this->pending[$requestId]['expected'] = max(0, $receivers - 1);

            // A reply can already have landed while the publish was in flight.
            $this->completeIfSatisfied($requestId);

            if (! $deferred->isComplete()) {
                // Suspend this fiber until the last expected reply lands, or
                // the window closes. The event loop keeps pumping the pub/sub
                // subscription, so replies reach publish() while we wait. The
                // window is only an upper bound now: a gather whose siblings
                // all answered in 5ms no longer costs a full second.
                $deferred->getFuture()->await();
            }

            $sets = $this->pending[$requestId]['sets'];
        } finally {
            EventLoop::cancel($timeout);

            unset($this->pending[$requestId]);
        }

        $sets[] = $this->local($application, $type, $options);

        return $this->merge($sets, $type);
    }

    /**
     * Buffer a sibling reply against the request it answers.
     *
     * A reply for an unknown request id is dropped: either the request already
     * completed or it belongs to another node. `$metrics` arrives straight off
     * the wire, so it is only trusted once it is known to be an array.
     */
    protected function recordReply(string $key, mixed $metrics): void
    {
        if (! array_key_exists($key, $this->pending) || ! is_array($metrics)) {
            return;
        }

        $this->pending[$key]['sets'][] = $metrics;

        $this->completeIfSatisfied($key);
    }

    /**
     * Complete the given request once every expected reply has been buffered.
     */
    protected function completeIfSatisfied(string $key): void
    {
        $pending = $this->pending[$key] ?? null;

        if ($pending === null || $pending['expected'] === null) {
            return;
        }

        if (count($pending['sets']) < $pending['expected']) {
            return;
        }

        $this->complete($key);
    }

    /**
     * Release the fiber awaiting the given request.
     *
     * Safe to call for a request that already completed or was cleaned up,
     * which is what makes the window timer and the last reply racing each
     * other harmless.
     */
    protected function complete(string $key): void
    {
        $deferred = $this->pending[$key]['deferred'] ?? null;

        if ($deferred !== null && ! $deferred->isComplete()) {
            $deferred->complete();
        }
    }

    /**
     * Merge metric sets gathered from every node into a single result set.
     *
     * @param  array<int, array<string|int, mixed>>  $sets
     * @return array<string|int, mixed>
     */
    protected function merge(array $sets, MetricType $type): array
    {
        return match ($type) {
            MetricType::CONNECTIONS => array_reduce($sets, fn ($carry, $set) => array_merge($carry, $set), []),
            MetricType::CHANNELS => $this->mergeChannels($sets),
            MetricType::CHANNEL => $this->mergeChannel($sets),
            MetricType::CHANNEL_USERS => collect($sets)->flatten(1)->unique()->values()->all(),
        };
    }

    /**
     * Merge multiple channel info sets into a single set.
     *
     * @param  array<int, array<string, mixed>>  $sets
     * @return array<string, mixed>
     */
    protected function mergeChannel(array $sets): array
    {
        return collect($sets)
            ->reduce(function ($carry, $set) {
                collect($set)->each(fn ($value, $key) => $carry->put($key, match ($key) {
                    'occupied' => $carry->get($key, false) || $value,
                    'user_count' => $carry->get($key, 0) + $value,
                    'subscription_count' => $carry->get($key, 0) + $value,
                    default => $value,
                }));

                return $carry;
            }, collect())
            ->all();
    }

    /**
     * Merge multiple sets of channel info into a single result set.
     *
     * @param  array<int, array<string, array<string, mixed>>>  $sets
     * @return array<string, array<string, mixed>>
     */
    protected function mergeChannels(array $sets): array
    {
        return collect($sets)
            ->reduce(function ($carry, $set) {
                collect($set)->each(function ($data, $channel) use ($carry) {
                    $metrics = $carry->get($channel, []);
                    $metrics[] = $data;
                    $carry->put($channel, $metrics);
                });

                return $carry;
            }, collect())
            ->map(fn ($metrics) => $this->mergeChannel($metrics))
            ->all();
    }

    /**
     * Determine whether cross-node metric gathering should be skipped.
     */
    protected function shouldNotPublishEvents(): bool
    {
        if (! app()->bound(ServerProvider::class)) {
            return true;
        }

        return app(ServerProvider::class)->shouldNotPublishEvents();
    }

    /**
     * Get the channel for the given application.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function channel(Application $application, array $options): array
    {
        return $this->info($application, $options['channel'] ?? '', $options['info'] ?? '');
    }

    /**
     * Get the channels for the given application.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, array<string, mixed>>
     */
    protected function channelsMetric(Application $application, array $options): array
    {
        if (! empty($options['channels'])) {
            return $this->infoForChannels($application, $options['channels'], $options['info'] ?? '');
        }

        $channels = collect($this->channels->for($application)->all());

        if ($filter = ($options['filter'] ?? false)) {
            $channels = $channels->filter(fn ($channel) => Str::startsWith($channel->name(), $filter));
        }

        $channels = $channels->filter(fn ($channel) => count($channel->connections()) > 0);

        return $this->infoForChannels(
            $application,
            $channels->values()->all(),
            $options['info'] ?? ''
        );
    }

    /**
     * Get the channel users for the given application.
     *
     * @param  array<string, mixed>  $options
     * @return array<int, array<string, mixed>>
     */
    protected function channelUsers(Application $application, array $options): array
    {
        $channel = $this->channels->for($application)->find($options['channel'] ?? '');

        if (! $channel) {
            return [];
        }

        return collect($channel->connections())
            ->map(fn ($connection) => $connection->data())
            ->unique('user_id')
            ->map(fn ($data) => ['id' => $data['user_id']])
            ->values()
            ->all();
    }

    /**
     * Get the connections for the given application.
     *
     * @return array<string, ChannelConnection>
     */
    protected function connections(Application $application): array
    {
        return $this->channels->for($application)->connections();
    }
}

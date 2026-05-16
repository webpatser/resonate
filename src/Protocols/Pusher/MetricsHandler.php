<?php

namespace Webpatser\Resonate\Protocols\Pusher;

use Illuminate\Support\Str;
use Webpatser\Resonate\Application;
use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Protocols\Pusher\Concerns\InteractsWithChannelInformation;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;

use function Fledge\Async\delay;

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
 *  - When scaling is on, `gather()` publishes a `metrics` request envelope,
 *    then suspends the fiber for a bounded collection window via
 *    `Fledge\Async\delay()`. The event loop keeps running, only this fiber
 *    parks. Sibling replies arrive on the pub/sub channel and are routed here
 *    through {@see publish()}, which buffers them keyed by request id. When
 *    the window closes the buffered replies are merged with this node's own
 *    local metrics.
 *  - {@see publish()} is the responder side too: a `metrics` *request* makes
 *    this node gather its local metrics and publish a `metrics` *reply*.
 *
 * Nothing here is PHP-`serialize()`d; every envelope field is JSON-native.
 * There is no `PendingMetric` object: a plain array keyed by request id is
 * enough for correlation.
 */
class MetricsHandler
{
    use InteractsWithChannelInformation;

    /**
     * The collection window, in seconds, to wait for sibling replies.
     */
    protected float $collectionWindow = 1.0;

    /**
     * Buffered sibling replies, keyed by request id.
     *
     * @var array<string, array<int, array<string|int, mixed>>>
     */
    protected array $replies = [];

    /**
     * Create an instance of the metrics handler.
     */
    public function __construct(protected ChannelManager $channels)
    {
        //
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
     *  - a *request* (`payload.type` + `payload.options` set): this node
     *    gathers its local metrics for the requested type and publishes a
     *    *reply* envelope back onto the pub/sub channel.
     *  - a *reply* (`payload.metrics` set): if this node is currently
     *    awaiting the matching request id, the metrics are appended to that
     *    request's buffer.
     *
     * @param  array{application: Application, payload: array<string, mixed>}  $envelope
     */
    public function publish(array $envelope): void
    {
        $application = $envelope['application'];
        $payload = $envelope['payload'];
        $key = $payload['key'] ?? null;

        if ($key === null) {
            return;
        }

        // A reply for a request this node is awaiting.
        if (array_key_exists('metrics', $payload)) {
            if (array_key_exists($key, $this->replies)) {
                $this->replies[$key][] = $payload['metrics'];
            }

            return;
        }

        // A request from a sibling node: answer with our local metrics.
        if (! isset($payload['type'])) {
            return;
        }

        app(PubSubProvider::class)->publish([
            'type' => 'metrics',
            'application' => $application->id(),
            'payload' => [
                'key' => $key,
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

        $this->replies[$requestId] = [];

        try {
            app(PubSubProvider::class)->publish([
                'type' => 'metrics',
                'application' => $application->id(),
                'payload' => [
                    'key' => $requestId,
                    'type' => $type->value,
                    'options' => $options,
                ],
            ]);

            // Suspend this fiber for the collection window. The event loop
            // keeps pumping the pub/sub subscription, so sibling replies land
            // in $this->replies[$requestId] via publish() while we wait.
            delay($this->collectionWindow);

            $sets = $this->replies[$requestId];
        } finally {
            unset($this->replies[$requestId]);
        }

        $sets[] = $this->local($application, $type, $options);

        return $this->merge($sets, $type);
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
            $channels->all(),
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
     * @return array<string, \Webpatser\Resonate\Protocols\Pusher\Channels\ChannelConnection>
     */
    protected function connections(Application $application): array
    {
        return $this->channels->for($application)->connections();
    }
}

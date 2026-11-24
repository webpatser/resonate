<?php

namespace Webpatser\Resonate\Telemetry;

use Fledge\Loop;
use Illuminate\Contracts\Events\Dispatcher;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use Throwable;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Events\ConnectionPruned;
use Webpatser\Resonate\Events\MessageSent;
use Webpatser\Resonate\Loggers\Log;
use Webpatser\Resonate\Plugins\Contracts\MessageInterceptor;
use Webpatser\Resonate\Plugins\Contracts\ServerPlugin;
use Webpatser\Resonate\Plugins\MessageDisposition;
use Webpatser\Resonate\Plugins\PluginContext;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;

/**
 * Exports Resonate's connection, channel and message numbers as OpenTelemetry metrics.
 *
 * Opt-in: the service provider adds this plugin only when
 * `reverb.servers.reverb.otel_metrics` is on, `open-telemetry/api` is installed
 * and a {@see MeterProviderInterface} is bound in the container (which
 * `webpatser/fledge-apm` does when its metrics are enabled).
 *
 * The only attribute is the Resonate app id. Channel names, connection ids,
 * socket ids, user ids and event names never become attributes.
 *
 * Every instrument is asynchronous and is created in `boot()`, which only the
 * server command calls, so a process that resolves the plugin without serving
 * exports nothing. The hot paths (every inbound message, every outbound frame
 * to every connection) only bump a per-app integer; the SDK reads the totals
 * when the reader collects and turns them into deltas. Connection and channel
 * gauges read the live channel manager per app at that moment. No callback can
 * throw into the collect.
 */
class OtelMetricsPlugin implements MessageInterceptor, ServerPlugin
{
    public const APP_ATTRIBUTE = 'resonate.app.id';

    /**
     * Messages received from clients, per app id.
     *
     * @var array<string, int>
     */
    protected array $received = [];

    /**
     * Frames sent to clients, per app id.
     *
     * @var array<string, int>
     */
    protected array $sent = [];

    /**
     * Stale connections pruned, per app id.
     *
     * @var array<string, int>
     */
    protected array $pruned = [];

    /**
     * Attribute arrays built once per app id and reused on every observation.
     *
     * @var array<string, array<non-empty-string, string>>
     */
    protected array $attributes = [];

    /**
     * Whether the instruments and listeners are registered.
     */
    protected bool $booted = false;

    /**
     * The fledge-apm facade that starts the export cadence, referenced by name
     * because that package is optional.
     */
    protected string $metricsClass = 'Fledge\Apm\Metrics\Metrics';

    /**
     * Create a new plugin instance.
     */
    public function __construct(
        protected MeterProviderInterface $provider,
        protected ChannelManager $channels,
        protected ApplicationProvider $applications,
        protected Dispatcher $events,
    ) {
        //
    }

    /**
     * Whether the guard that adds this plugin passes: the config flag, the API
     * package, and a meter provider binding in the container.
     */
    public static function enabled(): bool
    {
        return (bool) config('reverb.servers.reverb.otel_metrics', false)
            && interface_exists(MeterProviderInterface::class)
            && app()->bound(MeterProviderInterface::class);
    }

    /**
     * Create the instruments, listen for the events core has no plugin hook
     * for, and start the export cadence. Runs once, in the server process.
     */
    public function boot(PluginContext $context): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $meter = $this->provider->getMeter('resonate');

        $meter->createObservableGauge('resonate.connection.count', '{connection}', 'Open WebSocket connections')
            ->observe(function (ObserverInterface $observer): void {
                $this->observeApps($observer, fn (ChannelManager $scoped): int => $scoped->connectionCount());
            });

        $meter->createObservableGauge('resonate.channel.count', '{channel}', 'Open channels')
            ->observe(function (ObserverInterface $observer): void {
                $this->observeApps($observer, fn (ChannelManager $scoped): int => count($scoped->all()));
            });

        $meter->createObservableCounter('resonate.connection.pruned', '{connection}', 'Stale connections pruned')
            ->observe(function (ObserverInterface $observer): void {
                $this->observeTotals($observer, $this->pruned);
            });

        $meter->createObservableCounter('resonate.message.received', '{message}', 'Messages received from clients')
            ->observe(function (ObserverInterface $observer): void {
                $this->observeTotals($observer, $this->received);
            });

        $meter->createObservableCounter('resonate.message.sent', '{message}', 'Messages sent to clients, one per receiving connection')
            ->observe(function (ObserverInterface $observer): void {
                $this->observeTotals($observer, $this->sent);
            });

        // Fired once per frame per receiving connection, so this stays an
        // array increment.
        $this->events->listen(MessageSent::class, function (MessageSent $event): void {
            $appId = $event->connection->app()->id();
            $this->sent[$appId] = ($this->sent[$appId] ?? 0) + 1;
        });

        $this->events->listen(ConnectionPruned::class, function (ConnectionPruned $event): void {
            $appId = $event->connection->app()->id();
            $this->pruned[$appId] = ($this->pruned[$appId] ?? 0) + 1;
        });

        // Plugins boot before the server enters the loop, and fledge-apm only
        // starts its collect timer when the loop is already running, so mark
        // on the first loop turn. A process whose loop never runs leaves this
        // one-shot callback queued and unrun; it holds no resources.
        $metrics = $this->metricsClass;

        Loop::defer(function () use ($metrics): void {
            try {
                if (class_exists($metrics)) {
                    $metrics::markLongRunning('resonate');
                }
            } catch (Throwable $e) {
                Log::error('OpenTelemetry metrics cadence failed to start: '.$e->getMessage());
            }
        });
    }

    /**
     * Count the message and let it route normally.
     */
    public function onMessage(Connection $from, array $event): MessageDisposition
    {
        $appId = $from->app()->id();
        $this->received[$appId] = ($this->received[$appId] ?? 0) + 1;

        return MessageDisposition::Relay;
    }

    /**
     * Report one value per app, read from the app's channel manager view.
     *
     * @param  callable(ChannelManager): int  $read
     */
    protected function observeApps(ObserverInterface $observer, callable $read): void
    {
        try {
            foreach ($this->applications->all() as $application) {
                $observer->observe($read($this->channels->for($application)), $this->attributesFor($application->id()));
            }
        } catch (Throwable $e) {
            Log::error('OpenTelemetry metrics callback failed: '.$e->getMessage());
        }
    }

    /**
     * Report the running total of every app that has one.
     *
     * @param  array<string, int>  $totals
     */
    protected function observeTotals(ObserverInterface $observer, array $totals): void
    {
        foreach ($totals as $appId => $total) {
            $observer->observe($total, $this->attributesFor((string) $appId));
        }
    }

    /**
     * The shared attribute array for an app id.
     *
     * @return array<non-empty-string, string>
     */
    protected function attributesFor(string $appId): array
    {
        return $this->attributes[$appId] ??= [self::APP_ATTRIBUTE => $appId];
    }
}

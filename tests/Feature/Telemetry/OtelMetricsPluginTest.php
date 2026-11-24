<?php

use Fledge\Loop;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\TemporalityPreference;
use Webpatser\Resonate\Application;
use Webpatser\Resonate\ConfigApplicationProvider;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Events\ConnectionPruned;
use Webpatser\Resonate\Events\MessageSent;
use Webpatser\Resonate\Plugins\MessageDisposition;
use Webpatser\Resonate\Plugins\PluginContext;
use Webpatser\Resonate\Plugins\PluginManager;
use Webpatser\Resonate\Telemetry\OtelMetricsPlugin;
use Webpatser\Resonate\Tests\Fakes\FakeApmMetrics;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\Fakes\FakeServerPlugin;

/**
 * Resolve a fresh plugin manager from the container.
 */
function freshPluginManager(): PluginManager
{
    app()->forgetInstance(PluginManager::class);

    return app(PluginManager::class);
}

/**
 * The plugins the manager holds, in registration order.
 *
 * @return array<int, object>
 */
function managerPlugins(PluginManager $manager): array
{
    return (new ReflectionProperty($manager, 'plugins'))->getValue($manager);
}

/**
 * Bind a real SDK meter provider with an in-memory exporter using delta
 * temporality, as fledge-apm exports; return a reader that collects and gives
 * back the data points of one instrument.
 *
 * @return Closure(string): array<int, array{value: int|float, attributes: array<string, mixed>}>
 */
function bindMeterProvider(): Closure
{
    $exporter = new InMemoryExporter(new ArrayObject, TemporalityPreference::delta());
    $reader = new ExportingReader($exporter);
    $provider = (new MeterProviderBuilder)->addReader($reader)->build();

    app()->instance(MeterProviderInterface::class, $provider);

    return function (string $name) use ($reader, $exporter): array {
        $reader->collect();

        $points = [];

        foreach ($exporter->collect(true) as $metric) {
            if ($metric->name !== $name) {
                continue;
            }

            foreach ($metric->data->dataPoints as $point) {
                $points[] = ['value' => $point->value, 'attributes' => $point->attributes->toArray()];
            }
        }

        return $points;
    };
}

it('does not add the plugin when the config flag is off', function () {
    config(['reverb.servers.reverb.otel_metrics' => false]);
    bindMeterProvider();

    expect(freshPluginManager()->hasPlugins())->toBeFalse();
});

it('does not add the plugin when no meter provider is bound', function () {
    config(['reverb.servers.reverb.otel_metrics' => true]);

    expect(freshPluginManager()->hasPlugins())->toBeFalse();
});

it('adds the plugin first when the flag is on and a meter provider is bound', function () {
    config(['reverb.servers.reverb.otel_metrics' => true, 'reverb.servers.reverb.plugins' => [FakeServerPlugin::class]]);
    bindMeterProvider();

    $plugins = managerPlugins(freshPluginManager());

    expect($plugins)->toHaveCount(2)
        ->and($plugins[0])->toBeInstanceOf(OtelMetricsPlugin::class)
        ->and($plugins[1])->toBeInstanceOf(FakeServerPlugin::class);
});

it('exports nothing until the server boots it', function () {
    config(['reverb.servers.reverb.otel_metrics' => true]);
    $collect = bindMeterProvider();
    freshPluginManager();

    openConnection('public-one');

    expect($collect('resonate.connection.count'))->toBe([]);
});

it('moves each instrument by the right amount with only the app id as attribute', function () {
    config(['reverb.servers.reverb.otel_metrics' => true]);
    $collect = bindMeterProvider();
    $manager = freshPluginManager();
    $manager->boot();
    $manager->boot();

    $appId = app(ApplicationProvider::class)->all()->first()->id();
    $expected = [OtelMetricsPlugin::APP_ATTRIBUTE => $appId];

    // Connect: two connections, one subscribed to two channels.
    $first = openConnection('public-one');
    $second = openConnection();
    channels()->findOrCreate('public-two')->subscribe($first);
    $manager->notifyOpen($first);
    $manager->notifyOpen($second);

    expect($collect('resonate.connection.count'))->toBe([['value' => 2, 'attributes' => $expected]])
        ->and($collect('resonate.channel.count'))->toBe([['value' => 2, 'attributes' => $expected]]);

    // Disconnect one.
    channels()->unsubscribeFromAll($second);
    channels()->removeConnection($second);
    $manager->notifyClose($second);

    expect($collect('resonate.connection.count'))->toBe([['value' => 1, 'attributes' => $expected]]);

    // Prune, counted once per event even though boot ran twice.
    ConnectionPruned::dispatch($second);
    ConnectionPruned::dispatch($second);

    expect($collect('resonate.connection.pruned'))->toBe([['value' => 2, 'attributes' => $expected]]);

    // Message in: three, each relayed untouched.
    foreach (range(1, 3) as $_) {
        expect($manager->interceptMessage($first, ['event' => 'client-x', 'channel' => 'public-one']))
            ->toBe(MessageDisposition::Relay);
    }

    expect($collect('resonate.message.received'))->toBe([['value' => 3, 'attributes' => $expected]]);

    // Message out: four.
    foreach (range(1, 4) as $_) {
        MessageSent::dispatch($first, '{"event":"x","channel":"public-one"}');
    }

    expect($collect('resonate.message.sent'))->toBe([['value' => 4, 'attributes' => $expected]]);

    // No instrument carries anything but the app id.
    foreach (['resonate.connection.count', 'resonate.channel.count', 'resonate.connection.pruned', 'resonate.message.received', 'resonate.message.sent'] as $name) {
        $points = $collect($name);

        expect($points)->not->toBe([]);

        foreach ($points as $point) {
            expect(array_keys($point['attributes']))->toBe([OtelMetricsPlugin::APP_ATTRIBUTE]);
        }
    }
});

it('uses the units of the plan', function () {
    config(['reverb.servers.reverb.otel_metrics' => true]);
    $exporter = new InMemoryExporter;
    $reader = new ExportingReader($exporter);
    app()->instance(MeterProviderInterface::class, (new MeterProviderBuilder)->addReader($reader)->build());

    $manager = freshPluginManager();
    $manager->boot();
    $from = openConnection('public-one');
    $manager->interceptMessage($from, ['event' => 'client-x', 'channel' => 'public-one']);
    MessageSent::dispatch($from, '{}');
    ConnectionPruned::dispatch($from);
    $reader->collect();

    $units = [];

    foreach ($exporter->collect(true) as $metric) {
        $units[$metric->name] = $metric->unit;
    }

    ksort($units);

    expect($units)->toBe([
        'resonate.channel.count' => '{channel}',
        'resonate.connection.count' => '{connection}',
        'resonate.connection.pruned' => '{connection}',
        'resonate.message.received' => '{message}',
        'resonate.message.sent' => '{message}',
    ]);
});

it('leaves a later plugin in charge of the message disposition', function () {
    $fake = new FakeServerPlugin(MessageDisposition::Handled);
    app()->instance(FakeServerPlugin::class, $fake);
    config(['reverb.servers.reverb.otel_metrics' => true, 'reverb.servers.reverb.plugins' => [FakeServerPlugin::class]]);
    $collect = bindMeterProvider();

    $manager = freshPluginManager();
    $manager->boot();
    $from = openConnection('public-one');
    $event = ['event' => 'client-x', 'channel' => 'public-one'];

    expect($manager->interceptMessage($from, $event))->toBe(MessageDisposition::Handled)
        ->and($fake->messages)->toBe([$event])
        ->and($fake->booted)->toBeTrue()
        ->and($fake->context)->toBe(app(PluginContext::class))
        ->and($collect('resonate.message.received')[0]['value'])->toBe(1);
});

it('registers no ticks', function () {
    config(['reverb.servers.reverb.otel_metrics' => true]);
    bindMeterProvider();

    expect(freshPluginManager()->ticks())->toBe([]);
});

it('exports only the increase since the previous collect', function () {
    config(['reverb.servers.reverb.otel_metrics' => true]);
    $collect = bindMeterProvider();
    $manager = freshPluginManager();
    $manager->boot();

    $from = openConnection('public-one');
    $event = ['event' => 'client-x', 'channel' => 'public-one'];
    $expected = [OtelMetricsPlugin::APP_ATTRIBUTE => 'app-id'];

    foreach (range(1, 3) as $_) {
        $manager->interceptMessage($from, $event);
    }

    expect($collect('resonate.message.received'))->toBe([['value' => 3, 'attributes' => $expected]]);

    foreach (range(1, 2) as $_) {
        $manager->interceptMessage($from, $event);
    }

    expect($collect('resonate.message.received'))->toBe([['value' => 2, 'attributes' => $expected]]);
});

it('reports a second app that first shows up after a collect', function () {
    $apps = config('reverb.apps.apps');
    $apps[] = array_merge($apps[0], ['key' => 'second-key', 'secret' => 'second-secret', 'app_id' => 'second-id']);
    app()->instance(ApplicationProvider::class, new ConfigApplicationProvider(collect($apps)));

    config(['reverb.servers.reverb.otel_metrics' => true]);
    $collect = bindMeterProvider();
    $manager = freshPluginManager();
    $manager->boot();

    $event = ['event' => 'client-x', 'channel' => 'public-one'];
    $first = openConnection('public-one');

    foreach (range(1, 2) as $_) {
        $manager->interceptMessage($first, $event);
    }

    $perApp = fn (array $points): array => collect($points)
        ->mapWithKeys(fn (array $point): array => [$point['attributes'][OtelMetricsPlugin::APP_ATTRIBUTE] => $point['value']])
        ->sortKeys()
        ->all();

    expect($perApp($collect('resonate.message.received')))->toBe(['app-id' => 2]);

    $second = new class extends FakeConnection
    {
        public function app(): Application
        {
            return app(ApplicationProvider::class)->findByKey('second-key');
        }
    };

    foreach (range(1, 3) as $_) {
        $manager->interceptMessage($second, $event);
    }

    $manager->interceptMessage($first, $event);

    expect($perApp($collect('resonate.message.received')))->toBe(['app-id' => 1, 'second-id' => 3]);
});

it('starts the fledge-apm cadence on the first loop turn, not during boot', function () {
    FakeApmMetrics::$roles = [];
    config(['reverb.servers.reverb.otel_metrics' => true]);
    bindMeterProvider();

    $manager = freshPluginManager();
    $plugin = managerPlugins($manager)[0];
    (new ReflectionProperty($plugin, 'metricsClass'))->setValue($plugin, FakeApmMetrics::class);

    $manager->boot();
    $manager->boot();

    expect(FakeApmMetrics::$roles)->toBe([]);

    // Deferred callbacks run in queue order, so the plugin's runs before this stop.
    Loop::defer(fn () => Loop::getDriver()->stop());
    Loop::run();

    expect(FakeApmMetrics::$roles)->toBe(['resonate']);
});

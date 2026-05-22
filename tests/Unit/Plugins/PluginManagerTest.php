<?php

use Illuminate\Support\Facades\Event;
use Webpatser\Resonate\Events\MessageReceived;
use Webpatser\Resonate\Exceptions\InvalidApplication;
use Webpatser\Resonate\Plugins\MessageDisposition;
use Webpatser\Resonate\Plugins\PluginContext;
use Webpatser\Resonate\Plugins\PluginManager;
use Webpatser\Resonate\Protocols\Pusher\Server;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\Fakes\FakeServerPlugin;

beforeEach(function () {
    $this->server = $this->app->make(Server::class);
});

$subscribe = json_encode([
    'event' => 'pusher:subscribe',
    'data' => ['channel' => 'test-channel', 'auth' => ''],
]);

$unsubscribe = json_encode([
    'event' => 'pusher:unsubscribe',
    'data' => ['channel' => 'test-channel'],
]);

it('relays ordinary pusher traffic when a plugin returns Relay', function () use ($subscribe) {
    app(PluginManager::class)->register(new FakeServerPlugin(MessageDisposition::Relay));

    $this->server->message($connection = new FakeConnection, $subscribe);

    $connection->assertReceived([
        'event' => 'pusher_internal:subscription_succeeded',
        'data' => '{}',
        'channel' => 'test-channel',
    ]);
});

it('consumes a message a plugin marks Handled', function () use ($subscribe) {
    $plugin = new FakeServerPlugin(MessageDisposition::Handled);
    app(PluginManager::class)->register($plugin);

    $this->server->message($connection = new FakeConnection, $subscribe);

    expect($plugin->messages)->toHaveCount(1);
    $connection->assertNothingReceived();
});

it('does not route a message a plugin marks Rejected', function () use ($subscribe) {
    app(PluginManager::class)->register(new FakeServerPlugin(MessageDisposition::Rejected));

    $this->server->message($connection = new FakeConnection, $subscribe);

    $connection->assertNothingReceived();
});

it('does not fire MessageReceived when a plugin consumes the message', function () use ($subscribe) {
    Event::fake([MessageReceived::class]);
    app(PluginManager::class)->register(new FakeServerPlugin(MessageDisposition::Handled));

    $this->server->message(new FakeConnection, $subscribe);

    Event::assertNotDispatched(MessageReceived::class);
});

it('fires MessageReceived for relayed traffic', function () use ($subscribe) {
    Event::fake([MessageReceived::class]);
    app(PluginManager::class)->register(new FakeServerPlugin(MessageDisposition::Relay));

    $this->server->message(new FakeConnection, $subscribe);

    Event::assertDispatched(MessageReceived::class);
});

it('fires connection lifecycle hooks', function () use ($subscribe) {
    $plugin = new FakeServerPlugin;
    app(PluginManager::class)->register($plugin);

    $this->server->open($connection = new FakeConnection);
    $this->server->message($connection, $subscribe);
    $this->server->close($connection);

    expect($plugin->opened)->toContain($connection->id())
        ->and($plugin->subscribed)->toContain('test-channel')
        ->and($plugin->closed)->toContain($connection->id());
});

it('isolates a throwing plugin so the message still routes', function () use ($subscribe) {
    app(PluginManager::class)->register(new FakeServerPlugin(throwOnMessage: true));

    $this->server->message($connection = new FakeConnection, $subscribe);

    $connection->assertReceived([
        'event' => 'pusher_internal:subscription_succeeded',
        'data' => '{}',
        'channel' => 'test-channel',
    ]);
});

it('collects ticks from scheduler plugins', function () {
    $plugin = new FakeServerPlugin;
    app(PluginManager::class)->register($plugin);

    $ticks = app(PluginManager::class)->ticks();

    expect($ticks)->toHaveCount(1)
        ->and($ticks[0]['interval'])->toBe(1.0);

    ($ticks[0]['callback'])();

    expect($plugin->tickRuns)->toBe(1);
});

it('stores plugin-owned state on a connection', function () {
    $connection = new FakeConnection;

    $connection->setState('mg.role', 'customer');

    expect($connection->state('mg.role'))->toBe('customer')
        ->and($connection->hasState('mg.role'))->toBeTrue()
        ->and($connection->state('missing', 'fallback'))->toBe('fallback');

    $connection->forgetState('mg.role');

    expect($connection->hasState('mg.role'))->toBeFalse();
});

it('resolves the sole configured application from the plugin context', function () {
    $context = app(PluginContext::class);

    expect($context->application()->id())->toBe('app-id')
        ->and($context->application('app-id')->id())->toBe('app-id')
        ->and($context->applications())->toHaveCount(1);
});

it('broadcasts to a channel through the plugin context', function () use ($subscribe) {
    $this->server->message($connection = new FakeConnection, $subscribe);

    app(PluginContext::class)->broadcast(null, 'test-channel', 'plugin-event', ['hello' => 'world']);

    $connection->assertReceived([
        'event' => 'plugin-event',
        'channel' => 'test-channel',
        'data' => ['hello' => 'world'],
    ]);
});

it('reports whether any plugins are registered', function () {
    $manager = app(PluginManager::class);

    expect($manager->hasPlugins())->toBeFalse();

    $manager->register(new FakeServerPlugin);

    expect($manager->hasPlugins())->toBeTrue();
});

it('boots every registered plugin with the plugin context', function () {
    $plugin = new FakeServerPlugin;
    app(PluginManager::class)->register($plugin);

    app(PluginManager::class)->boot();

    expect($plugin->booted)->toBeTrue()
        ->and($plugin->context)->toBe(app(PluginContext::class));
});

it('isolates a plugin that throws during boot', function () {
    app(PluginManager::class)->register(new FakeServerPlugin(throwOnBoot: true));
    app(PluginManager::class)->register($healthy = new FakeServerPlugin);

    app(PluginManager::class)->boot();

    expect($healthy->booted)->toBeTrue();
});

it('isolates a plugin that throws from a lifecycle hook', function () {
    app(PluginManager::class)->register(new FakeServerPlugin(throwOnOpen: true));
    app(PluginManager::class)->register($healthy = new FakeServerPlugin);

    $this->server->open($connection = new FakeConnection);

    $connection->assertReceived([
        'event' => 'pusher:connection_established',
        'data' => json_encode([
            'socket_id' => $connection->id(),
            'activity_timeout' => 30,
        ]),
    ]);
    expect($healthy->opened)->toContain($connection->id());
});

it('sends an event to a single connection through the context', function () {
    app(PluginContext::class)->sendTo($connection = new FakeConnection, 'plugin-event', ['hello' => 'world']);

    $connection->assertReceived([
        'event' => 'plugin-event',
        'data' => json_encode(['hello' => 'world']),
    ]);
});

it('terminates a connection through the context', function () {
    app(PluginContext::class)->terminate($connection = new FakeConnection, 'goodbye', ['reason' => 'idle']);

    $connection->assertReceived([
        'event' => 'goodbye',
        'data' => json_encode(['reason' => 'idle']),
    ]);
    $connection->assertHasBeenTerminated();
});

it('lists the connections subscribed to a channel', function () use ($subscribe) {
    $this->server->message($connection = new FakeConnection, $subscribe);

    $found = app(PluginContext::class)->connectionsOn(null, 'test-channel');

    expect($found)->toHaveCount(1)
        ->and(collect($found)->first()->connection()->id())->toBe($connection->id());
});

it('throws when resolving an application without an id on a multi-app server', function () {
    $apps = $this->app['config']->get('reverb.apps.apps');
    $apps[1] = array_merge($apps[0], ['app_id' => 'app-id-2', 'key' => 'app-key-2']);
    $this->app['config']->set('reverb.apps.apps', $apps);

    app(PluginContext::class)->application();
})->throws(InvalidApplication::class);

it('fires onUnsubscribe when a connection unsubscribes from a channel', function () use ($subscribe, $unsubscribe) {
    $plugin = new FakeServerPlugin;
    app(PluginManager::class)->register($plugin);

    $this->server->message($connection = new FakeConnection, $subscribe);
    $this->server->message($connection, $unsubscribe);

    expect($plugin->unsubscribed)->toContain('test-channel');
});

it('isolates a throwing onUnsubscribe', function () use ($subscribe, $unsubscribe) {
    app(PluginManager::class)->register(new FakeServerPlugin(throwOnUnsubscribe: true));
    app(PluginManager::class)->register($healthy = new FakeServerPlugin);

    $this->server->message($connection = new FakeConnection, $subscribe);
    $this->server->message($connection, $unsubscribe);

    expect($healthy->unsubscribed)->toContain('test-channel');
});

it('reports a closing connection as onClose, not onUnsubscribe', function () use ($subscribe) {
    $plugin = new FakeServerPlugin;
    app(PluginManager::class)->register($plugin);

    $this->server->open($connection = new FakeConnection);
    $this->server->message($connection, $subscribe);
    $this->server->close($connection);

    expect($plugin->closed)->toContain($connection->id())
        ->and($plugin->unsubscribed)->toBeEmpty();
});

it('removes a connection from a channel through the plugin context without firing onUnsubscribe', function () use ($subscribe) {
    $plugin = new FakeServerPlugin;
    app(PluginManager::class)->register($plugin);

    $this->server->message($connection = new FakeConnection, $subscribe);

    expect(app(PluginContext::class)->connectionsOn(null, 'test-channel'))->toHaveCount(1);

    app(PluginContext::class)->unsubscribe($connection, 'test-channel');

    expect(app(PluginContext::class)->connectionsOn(null, 'test-channel'))->toBeEmpty()
        ->and($plugin->unsubscribed)->toBeEmpty();
});

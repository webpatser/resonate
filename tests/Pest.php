<?php

use Fledge\Async\Http\Server\Driver\Client;
use Fledge\Async\Http\Server\Request as FledgeRequest;
use Fledge\Loop;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use League\Uri\Http;
use Webpatser\Resonate\Application;
use Webpatser\Resonate\ConfigApplicationProvider;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Contracts\Logger;
use Webpatser\Resonate\Loggers\NullLogger;
use Webpatser\Resonate\Protocols\Pusher\Channels\ChannelConnection;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\Managers\ArrayChannelConnectionManager;
use Webpatser\Resonate\Protocols\Pusher\Managers\ArrayChannelManager;
use Webpatser\Resonate\Server\Router;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/Feature', __DIR__.'/Integration', __DIR__.'/Unit');

/*
 * Wire the container bindings the Pusher protocol layer depends on.
 *
 * The orchestrator registers these in the service provider for production;
 * until then the test suite binds them here so the Pusher protocol modules
 * (1D) can be exercised in isolation.
 */
uses()->beforeEach(function () {
    $this->app->singleton(Logger::class, fn () => new NullLogger);

    $this->app->singleton(ApplicationProvider::class, fn ($app) => new ConfigApplicationProvider(
        Collection::make($app['config']->get('reverb.apps.apps', []))
    ));

    $this->app->singleton(ChannelManager::class, fn () => new ArrayChannelManager);

    $this->app->bind(ChannelConnectionManager::class, fn () => new ArrayChannelConnectionManager);
})->in(__DIR__.'/Unit');

/**
 * Run the event loop until it has nothing referenced left to do.
 *
 * The watchdog is unreferenced so an idle loop returns immediately instead of
 * sitting out the timeout. It is the upper bound for a loop that will not
 * settle on its own, which the shared driver often will not: tests elsewhere in
 * the suite leave repeating watchers behind, and the driver cannot be swapped
 * for a clean one once any test has left a fiber suspended inside it.
 */
function drainLoop(float $timeout = 0.25): void
{
    $watchdog = Loop::delay($timeout, fn () => Loop::getDriver()->stop());

    Loop::unreference($watchdog);

    Loop::run();

    Loop::cancel($watchdog);
}

/**
 * Create a defined number of channel connections.
 *
 * @return array<int, ChannelConnection>
 */
function factory(int $count = 1, array $data = []): array
{
    return Collection::make(range(1, $count))->map(function () use ($data) {
        return new ChannelConnection(
            new FakeConnection((string) Str::uuid()),
            $data
        );
    })->all();
}

/**
 * Generate a valid Pusher authentication signature.
 */
function validAuth(string $connectionId, string $channel, ?string $data = null): string
{
    $signature = "{$connectionId}:{$channel}";

    if ($data) {
        $signature .= ":{$data}";
    }

    return 'app-key:'.hash_hmac('sha256', $signature, 'app-secret');
}

/**
 * Register a connection as open, optionally subscribing it to a channel.
 *
 * Mirrors what `Server::open()` does at admission. Subscribing alone is not
 * enough: the open-connection list, not channel membership, is what the ping
 * and prune jobs walk.
 */
function openConnection(?string $channel = null, ?Application $app = null): FakeConnection
{
    $connection = new FakeConnection;

    channels($app)->addConnection($connection);

    if ($channel !== null) {
        channels($app)->findOrCreate($channel)->subscribe($connection);
    }

    return $connection;
}

/**
 * Return the channel manager scoped to an application.
 */
function channels(?Application $app = null): ChannelManager
{
    return app(ChannelManager::class)
        ->for($app ?: app(ApplicationProvider::class)->all()->first());
}

/**
 * Build a fledge request signed the way Pusher signs it.
 *
 * @param  array<string, string>  $query
 * @param  array<string, string>  $routeParams
 */
function signedChannelRequest(string $method, string $path, array $query = [], string $body = '', array $routeParams = ['appId' => 'app-id']): FledgeRequest
{
    $query += [
        'auth_key' => 'app-key',
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
    ];

    $params = $query;

    if ($body !== '') {
        $params['body_md5'] = md5($body);
    }

    ksort($params);

    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = "{$key}={$value}";
    }

    $query['auth_signature'] = hash_hmac(
        'sha256',
        implode("\n", [$method, $path, implode('&', $pairs)]),
        'app-secret',
    );

    $uri = 'http://localhost'.$path.'?'.http_build_query($query);

    $request = new FledgeRequest(
        Mockery::mock(Client::class),
        $method,
        Http::new($uri),
        [],
        $body,
    );

    $request->setAttribute(Router::class, $routeParams);

    return $request;
}

/**
 * Subscribe a fresh connection to the given channel.
 *
 * @param  array<string, mixed>  $userInfo
 */
function subscribeToChannel(string $name, ?array $userInfo = null): FakeConnection
{
    $channel = channels()->findOrCreate($name);
    $connection = new FakeConnection;

    if ($userInfo !== null) {
        $data = json_encode($userInfo);
        $channel->subscribe($connection, validAuth($connection->id(), $name, $data), $data);
    } else {
        $channel->subscribe($connection);
    }

    return $connection;
}

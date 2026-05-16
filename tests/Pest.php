<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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
 * Return the channel manager scoped to an application.
 */
function channels(?Application $app = null): ChannelManager
{
    return app(ChannelManager::class)
        ->for($app ?: app(ApplicationProvider::class)->all()->first());
}

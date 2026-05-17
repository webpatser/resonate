<?php

namespace Webpatser\Resonate;

use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Pulse;
use Livewire\LivewireManager;
use Webpatser\Resonate\Console\Commands\InstallCommand;
use Webpatser\Resonate\Console\Commands\ReloadServer;
use Webpatser\Resonate\Console\Commands\RestartServer;
use Webpatser\Resonate\Console\Commands\StartServer;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Contracts\Logger;
use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Loggers\NullLogger;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\Managers\ArrayChannelConnectionManager;
use Webpatser\Resonate\Protocols\Pusher\Managers\ArrayChannelManager;
use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\Pulse\Livewire\Connections;
use Webpatser\Resonate\Pulse\Livewire\Messages;
use Webpatser\Resonate\Scaling\Contracts\PubSubIncomingMessageHandler;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;
use Webpatser\Resonate\Scaling\PusherPubSubIncomingMessageHandler;
use Webpatser\Resonate\Scaling\RedisPubSubProvider;
use Webpatser\Resonate\Scaling\ResonateServerProvider;

class ResonateServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Resonate reads the host application's existing `config/reverb.php`, so a
     * Reverb app can swap `laravel/reverb` for `webpatser/resonate` without any
     * configuration changes. The bundled stub is merged only so a fresh install
     * without the file still boots.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/reverb.php', 'reverb'
        );

        $this->app->instance(Logger::class, new NullLogger);

        $this->app->singleton(ApplicationManager::class);

        $this->app->bind(
            ApplicationProvider::class,
            fn ($app) => $app->make(ApplicationManager::class)->driver()
        );

        $this->app->singleton(ChannelManager::class, fn () => new ArrayChannelManager);

        $this->app->bind(ChannelConnectionManager::class, fn () => new ArrayChannelConnectionManager);

        $this->registerScaling();
    }

    /**
     * Register the horizontal scaling layer.
     *
     * The pub/sub bindings are added only when `reverb.servers.reverb.scaling.enabled`
     * is true. When scaling is off, `ServerProvider` and friends stay unbound; that
     * unbound state is how `EventDispatcher`/`MetricsHandler` detect "single-node,
     * dispatch synchronously".
     */
    protected function registerScaling(): void
    {
        if (! config('reverb.servers.reverb.scaling.enabled')) {
            return;
        }

        $this->app->singleton(
            ServerProvider::class,
            fn () => new ResonateServerProvider(config('reverb.servers.reverb'))
        );

        $this->app->singleton(
            PubSubIncomingMessageHandler::class,
            PusherPubSubIncomingMessageHandler::class
        );

        $this->app->singleton(
            PubSubProvider::class,
            fn ($app) => new RedisPubSubProvider(
                $app->make(PubSubIncomingMessageHandler::class),
                config('reverb.servers.reverb.scaling.channel', 'reverb'),
                config('reverb.servers.reverb.scaling.server', []),
            )
        );

        $this->app->singleton(MetricsHandler::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                StartServer::class,
                RestartServer::class,
                ReloadServer::class,
                InstallCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/reverb.php' => config_path('reverb.php'),
            ], ['reverb', 'reverb-config']);
        }

        $this->registerPulseIntegration();
    }

    /**
     * Wire the Pulse dashboard cards when laravel/pulse is installed.
     *
     * The Livewire component names and Blade view namespace stay `reverb.*` /
     * `reverb::` so any dashboard a host app already had pointing at Reverb's
     * Pulse cards keeps working after the swap. The recorders themselves are
     * not auto-registered; match Reverb's convention of letting users add
     * them to `config/pulse.php`.
     */
    protected function registerPulseIntegration(): void
    {
        if (! class_exists(Pulse::class) || ! $this->app->bound(Pulse::class)) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'reverb');

        $this->callAfterResolving('livewire', function (LivewireManager $livewire): void {
            $livewire->component('reverb.connections', Connections::class);
            $livewire->component('reverb.messages', Messages::class);
        });
    }
}

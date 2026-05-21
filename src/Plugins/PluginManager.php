<?php

namespace Webpatser\Resonate\Plugins;

use Throwable;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Loggers\Log;
use Webpatser\Resonate\Plugins\Contracts\ConnectionLifecycle;
use Webpatser\Resonate\Plugins\Contracts\MessageInterceptor;
use Webpatser\Resonate\Plugins\Contracts\ServerPlugin;
use Webpatser\Resonate\Plugins\Contracts\TickScheduler;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;

/**
 * Registry and dispatch fan-out for {@see ServerPlugin} instances.
 *
 * Plugins are sorted into per-capability lists once at registration. Every
 * fan-out call is wrapped in a try/catch so a misbehaving plugin can never
 * break the core connection lifecycle or message loop.
 */
class PluginManager
{
    /**
     * All registered plugins.
     *
     * @var array<int, ServerPlugin>
     */
    protected array $plugins = [];

    /**
     * Plugins implementing {@see MessageInterceptor}.
     *
     * @var array<int, MessageInterceptor>
     */
    protected array $interceptors = [];

    /**
     * Plugins implementing {@see ConnectionLifecycle}.
     *
     * @var array<int, ConnectionLifecycle>
     */
    protected array $lifecycle = [];

    /**
     * Plugins implementing {@see TickScheduler}.
     *
     * @var array<int, TickScheduler>
     */
    protected array $schedulers = [];

    /**
     * Create a new plugin manager.
     */
    public function __construct(protected PluginContext $context)
    {
        //
    }

    /**
     * Register a plugin and index it by the capabilities it implements.
     */
    public function register(ServerPlugin $plugin): void
    {
        $this->plugins[] = $plugin;

        if ($plugin instanceof MessageInterceptor) {
            $this->interceptors[] = $plugin;
        }

        if ($plugin instanceof ConnectionLifecycle) {
            $this->lifecycle[] = $plugin;
        }

        if ($plugin instanceof TickScheduler) {
            $this->schedulers[] = $plugin;
        }
    }

    /**
     * Determine whether any plugins are registered.
     */
    public function hasPlugins(): bool
    {
        return $this->plugins !== [];
    }

    /**
     * Boot every registered plugin.
     */
    public function boot(): void
    {
        foreach ($this->plugins as $plugin) {
            try {
                $plugin->boot($this->context);
            } catch (Throwable $e) {
                Log::error('Plugin boot failed ('.$plugin::class.'): '.$e->getMessage());
            }
        }
    }

    /**
     * Run an inbound message through the interceptor chain.
     *
     * The first plugin to return Handled or Rejected short-circuits the chain.
     * If every plugin returns Relay, the message routes normally.
     *
     * @param  array{event:string,channel?:string,data?:mixed}  $event
     */
    public function interceptMessage(Connection $from, array $event): MessageDisposition
    {
        foreach ($this->interceptors as $interceptor) {
            try {
                $disposition = $interceptor->onMessage($from, $event);
            } catch (Throwable $e) {
                Log::error('Plugin onMessage failed ('.$interceptor::class.'): '.$e->getMessage());

                continue;
            }

            if ($disposition !== MessageDisposition::Relay) {
                return $disposition;
            }
        }

        return MessageDisposition::Relay;
    }

    /**
     * Notify lifecycle plugins that a connection was opened.
     */
    public function notifyOpen(Connection $connection): void
    {
        foreach ($this->lifecycle as $plugin) {
            try {
                $plugin->onOpen($connection);
            } catch (Throwable $e) {
                Log::error('Plugin onOpen failed ('.$plugin::class.'): '.$e->getMessage());
            }
        }
    }

    /**
     * Notify lifecycle plugins that a connection was closed.
     */
    public function notifyClose(Connection $connection): void
    {
        foreach ($this->lifecycle as $plugin) {
            try {
                $plugin->onClose($connection);
            } catch (Throwable $e) {
                Log::error('Plugin onClose failed ('.$plugin::class.'): '.$e->getMessage());
            }
        }
    }

    /**
     * Notify lifecycle plugins that a connection subscribed to a channel.
     */
    public function notifySubscribe(Connection $connection, Channel $channel): void
    {
        foreach ($this->lifecycle as $plugin) {
            try {
                $plugin->onSubscribe($connection, $channel);
            } catch (Throwable $e) {
                Log::error('Plugin onSubscribe failed ('.$plugin::class.'): '.$e->getMessage());
            }
        }
    }

    /**
     * Collect every periodic tick registered by scheduler plugins.
     *
     * @return array<int, array{interval: float, callback: callable():void}>
     */
    public function ticks(): array
    {
        $ticks = [];

        foreach ($this->schedulers as $plugin) {
            try {
                foreach ($plugin->ticks() as $tick) {
                    $ticks[] = $tick;
                }
            } catch (Throwable $e) {
                Log::error('Plugin ticks() failed ('.$plugin::class.'): '.$e->getMessage());
            }
        }

        return $ticks;
    }
}

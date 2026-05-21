<?php

namespace Webpatser\Resonate\Plugins\Contracts;

use Webpatser\Resonate\Plugins\PluginContext;

/**
 * A server-side extension loaded into the Resonate process.
 *
 * Resonate itself is a product-agnostic Pusher relay; a {@see ServerPlugin} is
 * how a host application adds its own logic (timers, message handling, custom
 * lifecycle behaviour) without coupling Resonate to that product.
 *
 * A plugin implements this marker contract plus any of the capability
 * sub-interfaces it needs: {@see MessageInterceptor}, {@see ConnectionLifecycle},
 * {@see TickScheduler}.
 */
interface ServerPlugin
{
    /**
     * Boot the plugin once at server start, on the event loop, before any
     * connections are accepted. Open long-lived resources (async DB/Redis
     * pools) here and keep a reference to the {@see PluginContext}.
     */
    public function boot(PluginContext $context): void;
}

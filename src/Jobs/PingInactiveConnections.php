<?php

namespace Webpatser\Resonate\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Loggers\Log;
use Webpatser\Resonate\Plugins\PluginManager;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\EventHandler;

class PingInactiveConnections
{
    use Dispatchable;

    /**
     * Execute the job.
     */
    public function handle(ChannelManager $channels): void
    {
        Log::info('Pinging Inactive Connections');

        $pusher = new EventHandler($channels, app(PluginManager::class));

        app(ApplicationProvider::class)
            ->all()
            ->each(function ($application) use ($channels, $pusher) {
                // Every open connection, not just the subscribed ones. Walking
                // channel membership skipped connections that never sent
                // `pusher:subscribe`, so they were never pinged, never went
                // stale, and never got pruned while still holding a slot.
                foreach ($channels->for($application)->openConnections() as $connection) {
                    if ($connection->isActive()) {
                        continue;
                    }

                    $pusher->ping($connection);

                    Log::info('Connection Pinged', $connection->id());
                }
            });
    }
}

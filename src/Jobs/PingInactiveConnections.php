<?php

namespace Webpatser\Resonate\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Loggers\Log;
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

        $pusher = new EventHandler($channels);

        app(ApplicationProvider::class)
            ->all()
            ->each(function ($application) use ($channels, $pusher) {
                foreach ($channels->for($application)->connections() as $connection) {
                    if ($connection->isActive()) {
                        continue;
                    }

                    $pusher->ping($connection->connection());

                    Log::info('Connection Pinged', $connection->id());
                }
            });
    }
}

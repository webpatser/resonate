<?php

namespace Webpatser\Resonate\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Events\ConnectionPruned;
use Webpatser\Resonate\Loggers\Log;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;

class PruneStaleConnections
{
    use Dispatchable;

    /**
     * Execute the job.
     */
    public function handle(ChannelManager $channels): void
    {
        Log::info('Pruning Stale Connections');

        app(ApplicationProvider::class)
            ->all()
            ->each(function ($application) use ($channels) {
                $scoped = $channels->for($application);

                // Every open connection, not just the subscribed ones. A
                // connection that never sent `pusher:subscribe` belongs to no
                // channel, so walking channel membership left it holding a slot
                // forever no matter how long it had been unresponsive.
                foreach ($scoped->openConnections() as $connection) {
                    if (! $connection->isStale()) {
                        continue;
                    }

                    $connection->send((string) json_encode([
                        'event' => 'pusher:error',
                        'data' => json_encode([
                            'code' => 4201,
                            'message' => 'Pong reply not received in time',
                        ]),
                    ]));

                    $scoped->unsubscribeFromAll($connection);

                    $scoped->removeConnection($connection);

                    $connection->disconnect();

                    Log::info('Connection Pruned', $connection->id());

                    ConnectionPruned::dispatch($connection);
                }
            });
    }
}

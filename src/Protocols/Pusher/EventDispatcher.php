<?php

namespace Webpatser\Resonate\Protocols\Pusher;

use Illuminate\Support\Arr;
use Webpatser\Resonate\Application;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;

class EventDispatcher
{
    /**
     * Dispatch a message to a channel.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function dispatch(Application $app, array $payload, ?Connection $connection = null, ?string $socketId = null): void
    {
        $server = app()->bound(ServerProvider::class) ? app(ServerProvider::class) : null;

        if (! $server || $server->shouldNotPublishEvents()) {
            static::dispatchSynchronously($app, $payload, $connection);

            return;
        }

        $data = [
            'type' => 'message',
            'application' => $app->id(),
            'payload' => $payload,
        ];

        $socketId ??= $connection?->id();

        if ($socketId !== null) {
            $data['socket_id'] = $socketId;
        }

        app(PubSubProvider::class)->publish($data);
    }

    /**
     * Notify all connections subscribed to the given channel.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function dispatchSynchronously(Application $app, array $payload, ?Connection $connection = null): void
    {
        $channels = Arr::wrap($payload['channels'] ?? $payload['channel'] ?? []);

        foreach ($channels as $channel) {
            unset($payload['channels']);

            if (! $channel = app(ChannelManager::class)->for($app)->find($channel)) {
                continue;
            }

            $payload['channel'] = $channel->name();

            // Protocol events (member_added, member_removed) are delivered but
            // never stored, so a cache channel keeps its last real payload.
            if (is_string($payload['event'] ?? null) && str_starts_with($payload['event'], 'pusher_internal:')) {
                $channel->broadcastInternally($payload, $connection);

                continue;
            }

            $channel->broadcast($payload, $connection);
        }
    }
}

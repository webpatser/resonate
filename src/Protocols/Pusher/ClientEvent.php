<?php

namespace Webpatser\Resonate\Protocols\Pusher;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;

class ClientEvent
{
    /**
     * Handle a Pusher client event.
     */
    public static function handle(Connection $connection, array $event): void
    {
        Validator::make($event, [
            'event' => ['required', 'string'],
            'channel' => ['required', 'string'],
            'data' => ['nullable', 'array'],
        ])->validate();

        if (! Str::startsWith($event['event'], 'client-')) {
            return;
        }

        if (! isset($event['channel'])) {
            return;
        }

        $acceptClientEventsFrom = $connection->app()->acceptClientEventsFrom();

        if (! in_array($acceptClientEventsFrom, ['all', 'members'])) {
            // Client events are disabled, so we should reject the event...
            $connection->send(json_encode([
                'event' => 'pusher:error',
                'data' => json_encode([
                    'code' => 4301,
                    'message' => 'The app does not have client messaging enabled.',
                ]),
            ]));

            return;
        }

        // The Pusher protocol only permits client events on private-* and presence-* channels.
        // This applies in both 'all' and 'members' modes — the difference between the modes is
        // only how the membership claim is sourced, never whether the channel type is checked
        // or whether the sender must be subscribed.
        if (! Str::startsWith($event['channel'], ['private-', 'presence-'])) {
            $connection->send(json_encode([
                'event' => 'pusher:error',
                'data' => json_encode([
                    'code' => 4009,
                    'message' => 'Client events are only allowed on private and presence channels.',
                ]),
            ]));

            return;
        }

        $channel = app(ChannelManager::class)->find($event['channel']);

        $channelConnection = $channel?->find($connection);

        if (! $channelConnection) {
            $connection->send(json_encode([
                'event' => 'pusher:error',
                'data' => json_encode([
                    'code' => 4009,
                    'message' => 'The client is not a member of the specified channel.',
                ]),
            ]));

            return;
        }

        // Regenerate event payload, ensuring we only include the expected fields and the
        // authenticated user_id (sender-supplied user_id is never echoed).
        $rebroadcastEvent = [
            'event' => $event['event'],
            'channel' => $event['channel'],
            'data' => $event['data'] ?? null,
        ];

        if ($userId = $channelConnection->data('user_id')) {
            $rebroadcastEvent['user_id'] = $userId;
        }

        self::whisper(
            $connection,
            $rebroadcastEvent
        );
    }

    /**
     * Whisper a message to all connections on the channel associated with the event.
     */
    public static function whisper(Connection $connection, array $payload): void
    {
        EventDispatcher::dispatch(
            $connection->app(),
            $payload,
            $connection
        );
    }
}

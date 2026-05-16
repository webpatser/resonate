<?php

namespace Webpatser\Resonate\Protocols\Pusher\Concerns;

use Webpatser\Resonate\Application;
use Webpatser\Resonate\Protocols\Pusher\Channels\CacheChannel;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;
use Webpatser\Resonate\Protocols\Pusher\Channels\Concerns\InteractsWithPresenceChannels;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;

trait InteractsWithChannelInformation
{
    /**
     * Get meta / status information for the given channels.
     *
     * @param  array<int, Channel|string>  $channels
     * @return array<string, array<string, mixed>>
     */
    protected function infoForChannels(Application $application, array $channels, string $info): array
    {
        return collect($channels)->mapWithKeys(function ($channel) use ($application, $info) {
            $name = $channel instanceof Channel ? $channel->name() : $channel;

            return [$name => $this->info($application, $name, $info)];
        })->all();
    }

    /**
     * Get meta / status information for the given channel.
     *
     * @return array<string, mixed>
     */
    protected function info(Application $application, string $channel, string $info): array
    {
        $info = explode(',', $info);

        $channel = app(ChannelManager::class)->for($application)->find($channel);

        return array_filter(
            $channel ? $this->occupiedInfo($channel, $info) : $this->unoccupiedInfo($info),
            fn ($item) => $item !== null
        );
    }

    /**
     * Get channel information for the given occupied channel.
     *
     * @param  array<int, string>  $info
     * @return array<string, mixed>
     */
    private function occupiedInfo(Channel $channel, array $info): array
    {
        $count = count($channel->connections());

        return [
            'occupied' => in_array('occupied', $info) ? $count > 0 : null,
            'user_count' => in_array('user_count', $info) && $this->isPresenceChannel($channel) ? $this->userCount($channel) : null,
            'subscription_count' => in_array('subscription_count', $info) && ! $this->isPresenceChannel($channel) ? $count : null,
            'cache' => in_array('cache', $info) && $this->isCacheChannel($channel) ? $channel->cachedPayload() : null,
        ];
    }

    /**
     * Get channel information for the given unoccupied channel.
     *
     * @param  array<int, string>  $info
     * @return array<string, mixed>
     */
    private function unoccupiedInfo(array $info): array
    {
        return [
            'occupied' => in_array('occupied', $info) ? false : null,
        ];
    }

    /**
     * Determine if the given channel is a presence channel.
     */
    protected function isPresenceChannel(Channel $channel): bool
    {
        return in_array(InteractsWithPresenceChannels::class, class_uses($channel));
    }

    /**
     * Determine if the given channel is a cache channel.
     */
    protected function isCacheChannel(Channel $channel): bool
    {
        return $channel instanceof CacheChannel;
    }

    /**
     * Get the number of unique users subscribed to the presence channel.
     */
    protected function userCount(Channel $channel): int
    {
        return collect($channel->connections())
            ->map(fn ($connection) => $connection->data())
            ->unique('user_id')
            ->count();
    }
}

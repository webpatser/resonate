<?php

namespace Webpatser\Resonate\Plugins\Contracts;

use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;

/**
 * A plugin capability that observes connection lifecycle transitions so it can
 * maintain its own registries (Resonate only tracks subscribed connections).
 */
interface ConnectionLifecycle
{
    /**
     * Called after a connection is established and acknowledged.
     */
    public function onOpen(Connection $connection): void;

    /**
     * Called after a connection is closed and unsubscribed from all channels.
     */
    public function onClose(Connection $connection): void;

    /**
     * Called after a connection successfully subscribes to a channel.
     */
    public function onSubscribe(Connection $connection, Channel $channel): void;
}

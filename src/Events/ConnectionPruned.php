<?php

namespace Webpatser\Resonate\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Webpatser\Resonate\Protocols\Pusher\Channels\ChannelConnection;

class ConnectionPruned
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public ChannelConnection $connection)
    {
        //
    }
}

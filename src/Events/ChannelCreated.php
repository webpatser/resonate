<?php

namespace Webpatser\Resonate\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;

class ChannelCreated
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public Channel $channel)
    {
        //
    }
}

<?php

namespace Webpatser\Resonate\Protocols\Pusher\Channels;

use Webpatser\Resonate\Protocols\Pusher\Channels\Concerns\InteractsWithPresenceChannels;

class PresenceChannel extends PrivateChannel
{
    use InteractsWithPresenceChannels;
}

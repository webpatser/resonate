<?php

namespace Webpatser\Resonate\Protocols\Pusher\Channels;

use Webpatser\Resonate\Protocols\Pusher\Channels\Concerns\InteractsWithPresenceChannels;

class PresenceCacheChannel extends CacheChannel
{
    use InteractsWithPresenceChannels;
}

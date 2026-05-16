<?php

namespace Webpatser\Resonate\Protocols\Pusher\Channels;

use Webpatser\Resonate\Protocols\Pusher\Channels\Concerns\InteractsWithPrivateChannels;

class PrivateCacheChannel extends CacheChannel
{
    use InteractsWithPrivateChannels;
}

<?php

namespace Webpatser\Resonate\Protocols\Pusher\Channels;

use Webpatser\Resonate\Protocols\Pusher\Channels\Concerns\InteractsWithPrivateChannels;

class PrivateChannel extends Channel
{
    use InteractsWithPrivateChannels;
}

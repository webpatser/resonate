<?php

namespace Webpatser\Resonate\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Webpatser\Resonate\Contracts\Connection;

class MessageReceived
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public Connection $connection, public string $message)
    {
        //
    }
}

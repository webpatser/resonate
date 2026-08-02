<?php

namespace Webpatser\Resonate\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Webpatser\Resonate\Contracts\Connection;

class ConnectionPruned
{
    use Dispatchable;

    /**
     * Create a new event instance.
     *
     * The payload is the connection itself rather than a `ChannelConnection`
     * wrapper. Pruning now works from the open-connection list instead of
     * channel membership, and a connection that never subscribed has no
     * wrapper to hand out. Listeners that only read connection methods (`id()`,
     * `app()`, `lastSeenAt()`) are unaffected, since the wrapper proxied those
     * to this same object; a listener that called `data()` or `connection()` on
     * the payload needs updating.
     */
    public function __construct(public Connection $connection)
    {
        //
    }
}

<?php

namespace Webpatser\Resonate\Plugins\Contracts;

use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Plugins\MessageDisposition;

/**
 * A plugin capability that inspects inbound client messages before they reach
 * the standard Pusher routing.
 */
interface MessageInterceptor
{
    /**
     * Inspect a decoded inbound client message.
     *
     * Return {@see MessageDisposition::Handled} or {@see MessageDisposition::Rejected}
     * to consume the message, or {@see MessageDisposition::Relay} to let it route
     * normally. A plugin should return Relay for any event it does not own so
     * ordinary Pusher traffic is never disturbed.
     *
     * @param  array{event:string,channel?:string,data?:mixed}  $event
     */
    public function onMessage(Connection $from, array $event): MessageDisposition;
}

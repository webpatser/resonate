<?php

namespace Webpatser\Resonate\Plugins;

use Webpatser\Resonate\Protocols\Pusher\Server;

/**
 * The outcome a {@see Contracts\MessageInterceptor} returns for an inbound message.
 *
 * It tells the Pusher {@see Server} what to
 * do with a decoded client message after the plugin layer has inspected it.
 */
enum MessageDisposition
{
    /**
     * The plugin fully handled the message; skip normal Pusher routing.
     */
    case Handled;

    /**
     * The plugin rejected the message; skip routing. The plugin is responsible
     * for having sent its own error frame to the connection.
     */
    case Rejected;

    /**
     * Not a plugin message (or the plugin wants normal handling too); fall
     * through to the standard `pusher:` / `client-*` routing.
     */
    case Relay;
}

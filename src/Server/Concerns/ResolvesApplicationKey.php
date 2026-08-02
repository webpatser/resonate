<?php

namespace Webpatser\Resonate\Server\Concerns;

use Fledge\Async\Http\Server\Request;
use Webpatser\Resonate\Server\ApplicationClientFactory;
use Webpatser\Resonate\Server\Router;
use Webpatser\Resonate\Server\WebSocketHandler;

/**
 * Extracts the `{appKey}` route parameter from an upgrade request.
 *
 * Shared by the two places that need the application before the connection
 * exists: {@see ApplicationClientFactory}, which sizes the connection's frame
 * parser from the application's own `max_message_size`, and
 * {@see WebSocketHandler}, which builds the connection itself. Both run
 * against the same `Request` instance, so both must read the key the same way.
 */
trait ResolvesApplicationKey
{
    /**
     * Extract the {appKey} route parameter from the request.
     */
    protected function appKey(Request $request): ?string
    {
        if ($request->hasAttribute(Router::class)) {
            $arguments = $request->getAttribute(Router::class);

            if (is_array($arguments) && isset($arguments['appKey']) && is_string($arguments['appKey'])) {
                return $arguments['appKey'];
            }
        }

        // Fallback: derive the key from the request path (/app/{appKey}). The
        // regex is anchored to the full path and caps the key at 128 chars so
        // a proxy-injected prefix (e.g. `/foo/app/x`) cannot smuggle a key in,
        // and an arbitrarily long path segment cannot exhaust the lookup.
        if (preg_match('#^/app/([^/?]{1,128})$#', $request->getUri()->getPath(), $matches)) {
            return $matches[1];
        }

        return null;
    }
}

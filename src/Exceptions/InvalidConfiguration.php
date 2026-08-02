<?php

namespace Webpatser\Resonate\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a configured value cannot produce a working server.
 *
 * These are boot-time failures on purpose: a half-configured limit that
 * silently rejects or admits everything is worse than a server that refuses
 * to start and says which key is wrong.
 */
class InvalidConfiguration extends InvalidArgumentException
{
    //
}

<?php

namespace Webpatser\Resonate\Tests\Fakes;

use Webpatser\Resonate\Contracts\ServerProvider;

/**
 * A server provider that reports horizontal scaling as enabled.
 */
class ScaledServerProvider extends ServerProvider
{
    public function shouldPublishEvents(): bool
    {
        return true;
    }
}

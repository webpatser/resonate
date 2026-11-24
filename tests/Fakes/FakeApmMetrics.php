<?php

namespace Webpatser\Resonate\Tests\Fakes;

/**
 * Stands in for fledge-apm's `Metrics` facade, which is not installed here.
 */
class FakeApmMetrics
{
    /**
     * The roles passed to markLongRunning(), in call order.
     *
     * @var array<int, string>
     */
    public static array $roles = [];

    public static function markLongRunning(string $role): void
    {
        static::$roles[] = $role;
    }
}

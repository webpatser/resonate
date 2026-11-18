<?php

namespace Webpatser\Resonate\Tests\Fakes;

use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Protocols\Pusher\Channels\ChannelConnection;
use Webpatser\Resonate\Protocols\Pusher\Managers\ArrayChannelConnectionManager;

/**
 * The real per-channel connection store, counting how often it is read and written.
 *
 * Bound in place of the default through the container, so a test can count
 * how many channels a code path visits without replacing any behaviour.
 */
class CountingChannelConnectionManager extends ArrayChannelConnectionManager
{
    /**
     * Calls to all() across every channel since the last reset.
     */
    public static int $reads = 0;

    /**
     * Calls to remove() across every channel since the last reset.
     */
    public static int $removals = 0;

    /**
     * Calls to findById() (and so find()) across every channel since the last reset.
     */
    public static int $lookups = 0;

    /**
     * Reset the counters.
     */
    public static function reset(): void
    {
        static::$reads = 0;
        static::$removals = 0;
        static::$lookups = 0;
    }

    /**
     * Find a connection by its ID.
     */
    public function findById(string $id): ?ChannelConnection
    {
        static::$lookups++;

        return parent::findById($id);
    }

    /**
     * Remove a connection.
     */
    public function remove(Connection $connection): void
    {
        static::$removals++;

        parent::remove($connection);
    }

    /**
     * Get all of the connections.
     *
     * @return array<string, ChannelConnection>
     */
    public function all(): array
    {
        static::$reads++;

        return parent::all();
    }
}

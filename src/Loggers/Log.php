<?php

namespace Webpatser\Resonate\Loggers;

use Webpatser\Resonate\Contracts\Logger;

/**
 * @method static void info(string $title, ?string $message = null)
 * @method static void error(string $message)
 * @method static void message(string $message)
 * @method static void line(int $lines = 1)
 */
class Log
{
    /**
     * The logger instance.
     *
     * @var Logger
     */
    protected static $logger;

    /**
     * Proxy method calls to the logger instance.
     *
     * @param  string  $method
     * @param  array  $arguments
     * @return mixed
     */
    public static function __callStatic($method, $arguments)
    {
        static::$logger ??= app(Logger::class);

        return static::$logger->{$method}(...$arguments);
    }
}

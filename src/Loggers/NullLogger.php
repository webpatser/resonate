<?php

namespace Webpatser\Resonate\Loggers;

use Webpatser\Resonate\Contracts\Logger;

class NullLogger implements Logger
{
    /**
     * Log an informational message.
     */
    #[\Override]
    public function info(string $title, ?string $message = null): void
    {
        //
    }

    /**
     * Log an error message.
     */
    #[\Override]
    public function error(string $message): void
    {
        //
    }

    /**
     * Log a message sent to the server.
     */
    #[\Override]
    public function message(string $message): void
    {
        //
    }

    /**
     * Append a new line to the log.
     */
    #[\Override]
    public function line(int $lines = 1): void
    {
        //
    }
}

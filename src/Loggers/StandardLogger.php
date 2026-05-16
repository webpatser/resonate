<?php

namespace Webpatser\Resonate\Loggers;

use Illuminate\Support\Facades\Log;
use Webpatser\Resonate\Contracts\Logger;

class StandardLogger implements Logger
{
    /**
     * Log an informational message.
     */
    public function info(string $title, ?string $message = null): void
    {
        $output = $title;

        if ($message) {
            $output .= ': '.$message;
        }

        Log::info($output);
    }

    /**
     * Log an error message.
     */
    public function error(string $message): void
    {
        Log::error($message);
    }

    /**
     * Log a message sent to the server.
     */
    public function message(string $message): void
    {
        $decoded = json_decode($message, true);

        if (! is_array($decoded)) {
            return;
        }

        if (isset($decoded['data']) && is_string($decoded['data'])) {
            $decoded['data'] = json_decode($decoded['data'], true);
        }

        $decoded = Sanitizer::redact($decoded);

        Log::info(json_encode($decoded, JSON_PRETTY_PRINT));
    }

    /**
     * Append a new line to the log.
     */
    public function line(int $lines = 1): void
    {
        //
    }
}

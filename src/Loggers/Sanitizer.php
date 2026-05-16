<?php

namespace Webpatser\Resonate\Loggers;

/**
 * Redacts sensitive fields from a decoded Pusher message before it is logged.
 *
 * Private-channel subscribe frames carry an `auth` HMAC token; presence
 * subscribe frames carry a `channel_data` payload that may include user PII.
 * Neither belongs in a log file, so both are replaced with placeholders that
 * keep the rest of the message shape intact for debugging.
 */
class Sanitizer
{
    /**
     * Redact sensitive fields from a decoded message.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public static function redact(array $message): array
    {
        if (! isset($message['data']) || ! is_array($message['data'])) {
            return $message;
        }

        if (array_key_exists('auth', $message['data'])) {
            $message['data']['auth'] = '[redacted]';
        }

        if (array_key_exists('channel_data', $message['data'])) {
            $message['data']['channel_data'] = '[redacted: presence channel_data]';
        }

        return $message;
    }
}

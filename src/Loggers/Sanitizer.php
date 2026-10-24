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

    /**
     * Build the log line for an outbound broadcast.
     *
     * Member-added frames and anything sent on a private or presence channel
     * carry user identity and `user_info`, so only the event name and channel
     * are logged for them. Other payloads are logged in full.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function outbound(array $payload, string $encoded): string
    {
        $event = $payload['event'] ?? null;
        $channel = $payload['channel'] ?? null;

        $sensitive = $event === 'pusher_internal:member_added'
            || (is_string($channel) && (str_starts_with($channel, 'private-') || str_starts_with($channel, 'presence-')));

        if (! $sensitive) {
            return $encoded;
        }

        return (string) json_encode(['event' => $event, 'channel' => $channel]);
    }
}

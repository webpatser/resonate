<?php

namespace Webpatser\Resonate\Protocols\Pusher;

use Closure;

/**
 * The rules a channel name must satisfy to be accepted from a client or from
 * the REST API.
 *
 * Pusher restricts channel names to `A-Z a-z 0-9 _ - = @ , . ;`. Resonate used
 * to bound only the length, so a name could carry anything, which matters to
 * every plugin that embeds the name in a store key or a glob pattern (a roster
 * key, a replay stream). The `private-`, `private-encrypted-`, `presence-` and
 * `cache-` prefixes are plain members of that charset and need no special case.
 *
 * The one exception is the `#server-to-user-` prefix `pusher/pusher-php-server`
 * addresses `sendToUser()` messages to, and `resonate-users` joins on signin.
 * The `#` is outside the charset, and the user id after it is whatever the
 * application signed in, so only control characters are refused there.
 */
final class ChannelName
{
    /**
     * The channel prefix reserved for server-to-user messages.
     */
    public const USER_CHANNEL_PREFIX = '#server-to-user-';

    /**
     * The characters Pusher permits in a channel name.
     */
    public const PATTERN = '/\A[A-Za-z0-9_\-=@,.;]+\z/D';

    /**
     * Determine whether the given channel name is acceptable.
     *
     * The check is skipped when `servers.reverb.validate_channel_names` is
     * false.
     */
    public static function isValid(mixed $name): bool
    {
        if (! is_string($name)) {
            return false;
        }

        if (! config('reverb.servers.reverb.validate_channel_names', true)) {
            return true;
        }

        if (str_starts_with($name, self::USER_CHANNEL_PREFIX)) {
            $id = substr($name, strlen(self::USER_CHANNEL_PREFIX));

            return $id !== '' && preg_match('/[\x00-\x1F\x7F]/', $id) !== 1;
        }

        return preg_match(self::PATTERN, $name) === 1;
    }

    /**
     * Get a validation rule that fails for a channel name outside the charset.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! self::isValid($value)) {
                $fail('The :attribute is not a valid channel name.');
            }
        };
    }
}

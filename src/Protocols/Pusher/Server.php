<?php

namespace Webpatser\Resonate\Protocols\Pusher;

use Exception;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Events\MessageReceived;
use Webpatser\Resonate\Loggers\Log;
use Webpatser\Resonate\Plugins\MessageDisposition;
use Webpatser\Resonate\Plugins\PluginManager;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\Exceptions\ConnectionLimitExceeded;
use Webpatser\Resonate\Protocols\Pusher\Exceptions\InvalidOrigin;
use Webpatser\Resonate\Protocols\Pusher\Exceptions\MessageSizeExceeded;
use Webpatser\Resonate\Protocols\Pusher\Exceptions\PusherException;
use Webpatser\Resonate\Protocols\Pusher\Exceptions\RateLimitExceeded;

class Server
{
    /**
     * Create a new server instance.
     */
    public function __construct(
        protected ChannelManager $channels,
        protected EventHandler $handler,
        protected PluginManager $plugins,
    ) {
        //
    }

    /**
     * The rate limiter keys currently in play, mapped to when they expire.
     *
     * @var array<string, int>
     */
    protected array $rateLimitExpiry = [];

    /**
     * The last time expired rate limiter keys were swept.
     */
    protected int $rateLimitPrunedAt = 0;

    /**
     * The connection state key recording that a connection passed admission.
     *
     * Only an admitted connection was registered with the per-application
     * connection list, so only an admitted connection is removed on close.
     */
    public const ADMITTED = 'resonate.admitted';

    /**
     * Handle the a client connection.
     *
     * Returns false when the connection was rejected, in which case it has
     * already been sent an error frame and terminated. A rejected connection
     * must not be read from: leaving its socket open let a client that ignored
     * the error frame keep subscribing and whispering, which made the origin
     * allow-list and `max_connections` advisory only.
     */
    public function open(Connection $connection): bool
    {
        try {
            $this->ensureWithinConnectionLimit($connection);
            $this->verifyOrigin($connection);
        } catch (Exception $e) {
            $this->error($connection, $e);

            $connection->terminate();

            return false;
        }

        try {
            $this->channels->for($connection->app())->addConnection($connection);

            $connection->setState(self::ADMITTED, true);

            $connection->touch();

            $this->handler->handle($connection, 'pusher:connection_established');

            Log::info('Connection Established', $connection->id());

            $this->plugins->notifyOpen($connection);
        } catch (Exception $e) {
            $this->error($connection, $e);
        }

        return true;
    }

    /**
     * Handle a new message received by the connected client.
     */
    public function message(Connection $from, string $message): void
    {
        $maxSize = $from->app()->maxMessageSize();

        if ($maxSize > 0 && strlen($message) > $maxSize) {
            $this->error($from, new MessageSizeExceeded);

            return;
        }

        $from->touch();

        try {
            // The rate-limit check comes before any logging: a throttled flood
            // that still reached the logger made the limiter a way to amplify
            // disk writes rather than prevent work.
            $this->ensureWithinRateLimit($from);

            Log::info('Message Received', $from->id());
            Log::message($message);

            $event = json_decode($message, associative: true, flags: JSON_THROW_ON_ERROR);

            if (Str::isJson($event['data'] ?? null)) {
                $event['data'] = json_decode($event['data'], associative: true, flags: JSON_THROW_ON_ERROR);
            }

            Validator::make($event, ['event' => ['required', 'string']])->validate();

            // Give the plugin layer first refusal on the message. A plugin that
            // owns a custom event type returns Handled/Rejected to consume it;
            // Relay (the default for non-plugin traffic) falls through to the
            // standard Pusher / client-event routing untouched.
            $disposition = $this->plugins->interceptMessage($from, $event);

            if ($disposition === MessageDisposition::Relay) {
                match (Str::startsWith($event['event'], 'pusher:')) {
                    true => $this->handler->handle(
                        $from,
                        $event['event'],
                        empty($event['data']) ? [] : $event['data'],
                    ),
                    default => ClientEvent::handle($from, $event)
                };

                Log::info('Message Handled', $from->id());

                MessageReceived::dispatch($from, $message);
            }
        } catch (Throwable $e) {
            $this->error($from, $e);
        }
    }

    /**
     * Handle a low-level WebSocket control frame.
     */
    public function control(Connection $from, string $message): void
    {
        Log::info('Control Frame Received', $from->id());
        Log::message($message);

        $from->setUsesControlFrames();

        if (in_array($message, [Connection::CONTROL_PING, Connection::CONTROL_PONG], strict: true)) {
            $from->touch();
        }
    }

    /**
     * Handle a client disconnection.
     */
    public function close(Connection $connection): void
    {
        $scoped = $this->channels->for($connection->app());

        $scoped->unsubscribeFromAll($connection);

        $this->forgetConnectionRateLimit($connection);

        // Only release a connection that was actually registered. Rejected
        // connections never reached the registration in open(), and releasing
        // them walked the count below the live total, which reset the
        // `max_connections` quota for everyone on the node.
        if ($connection->hasState(self::ADMITTED)) {
            $connection->forgetState(self::ADMITTED);

            $scoped->removeConnection($connection);
        }

        $connection->disconnect();

        Log::info('Connection Closed', $connection->id());

        $this->plugins->notifyClose($connection);
    }

    /**
     * Handle an error.
     */
    public function error(Connection $connection, Throwable $exception): void
    {
        if ($exception instanceof PusherException) {
            $connection->send(json_encode($exception->payload()));

            Log::error('Message from '.$connection->id().' resulted in a pusher error');
            Log::info($exception->getMessage());

            return;
        }

        $connection->send(json_encode([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4200,
                'message' => 'Invalid message format',
            ]),
        ]));

        Log::error('Message from '.$connection->id().' resulted in an unknown error');
        Log::info($exception->getMessage());
    }

    /**
     * Ensure the server is within the connection limit.
     */
    protected function ensureWithinConnectionLimit(Connection $connection): void
    {
        if (! $connection->app()->hasMaxConnectionLimit()) {
            return;
        }

        $count = $this->channels->for($connection->app())->connectionCount();

        if ($count >= $connection->app()->maxConnections()) {
            throw new ConnectionLimitExceeded;
        }
    }

    /**
     * Ensure the connection is within the message rate limit.
     *
     * Two dimensions are counted, both with the configured quota:
     *
     * - the client (application plus remote address), which survives a
     *   reconnect. Keying on the socket id alone let a client that tripped the
     *   limit reset its quota by reconnecting, which `terminate_on_limit`
     *   actively invites.
     * - the connection (application plus socket id), which is cleared on close
     *   so a process serving many short-lived connections does not accumulate
     *   limiter entries.
     *
     * When the transport cannot report a remote address only the connection
     * dimension exists, and the reconnect reset comes back; see
     * {@see Connection::remoteAddress()}.
     *
     * @throws RateLimitExceeded
     */
    protected function ensureWithinRateLimit(Connection $connection): void
    {
        if (! $connection->app()->usesRateLimiting()) {
            return;
        }

        // Validated when the Application is built, so both values are present
        // and positive here. Reading `max_attempts` with no fallback used to
        // hand `null` to `tooManyAttempts()`, which rejected every connection
        // after its second message whenever the key was missing.
        $config = $connection->app()->rateLimiting();
        $maxAttempts = (int) $config['max_attempts'];
        $decaySeconds = (int) $config['decay_seconds'];

        $limiter = $this->limiter();

        $this->pruneExpiredRateLimits($limiter);

        $keys = $this->rateLimitKeys($connection);

        foreach ($keys as $key) {
            if (! $limiter->tooManyAttempts($key, $maxAttempts)) {
                continue;
            }

            if ($config['terminate_on_limit'] ?? false) {
                $connection->terminate();
            }

            throw new RateLimitExceeded;
        }

        foreach ($keys as $key) {
            $limiter->increment($key, $decaySeconds);

            $this->rateLimitExpiry[$key] = time() + $decaySeconds;
        }
    }

    /**
     * Get the rate limiter keys for the given connection, keyed by dimension.
     *
     * @return array<string, string>
     */
    protected function rateLimitKeys(Connection $connection): array
    {
        $prefix = 'resonate:message:'.$connection->app()->id();

        $keys = ['connection' => $prefix.':connection:'.$connection->id()];

        if (($address = $connection->remoteAddress()) !== null && $address !== '') {
            $keys['client'] = $prefix.':client:'.$address;
        }

        return $keys;
    }

    /**
     * Release the connection-scoped rate limiter entries for a closing connection.
     *
     * The client-scoped entry is deliberately left in place: clearing it would
     * hand a throttled client a fresh quota for the price of a reconnect.
     */
    protected function forgetConnectionRateLimit(Connection $connection): void
    {
        if (! $connection->app()->usesRateLimiting()) {
            return;
        }

        $key = $this->rateLimitKeys($connection)['connection'];

        $this->limiter()->clear($key);

        unset($this->rateLimitExpiry[$key]);
    }

    /**
     * Drop rate limiter entries whose decay window has already elapsed.
     *
     * The array cache store only evicts an expired entry when that same key is
     * read again, so a client-scoped entry for an address that never comes back
     * would hold two array slots (the counter and its timer) for the lifetime
     * of the process. Sweeping is capped at once a second.
     */
    protected function pruneExpiredRateLimits(RateLimiter $limiter): void
    {
        if (($now = time()) === $this->rateLimitPrunedAt) {
            return;
        }

        $this->rateLimitPrunedAt = $now;

        foreach ($this->rateLimitExpiry as $key => $expiresAt) {
            if ($expiresAt > $now) {
                continue;
            }

            $limiter->clear($key);

            unset($this->rateLimitExpiry[$key]);
        }
    }

    /**
     * Get a rate limiter backed by the in-process array store.
     */
    protected function limiter(): RateLimiter
    {
        return new RateLimiter(app('cache')->store('array'));
    }

    /**
     * Verify the origin of the connection.
     *
     * A bare `example.com` entry is the documented configuration format and
     * keeps matching on host alone, whatever scheme or port the client used.
     * An entry carrying a scheme (`https://example.com`) is matched against the
     * full origin instead, so it no longer admits `http://example.com` or
     * `https://example.com:8443`. Default ports are normalized away on both
     * sides, so `https://example.com` and `https://example.com:443` are one
     * pattern.
     *
     * @throws InvalidOrigin
     */
    protected function verifyOrigin(Connection $connection): void
    {
        $allowedOrigins = $connection->app()->allowedOrigins();

        // Strict, because a config that yields `true` in the array (say
        // `[env('REVERB_ALLOWED_ORIGINS', true)]`) is loosely equal to '*' and
        // silently turned origin verification off altogether.
        if (in_array('*', $allowedOrigins, strict: true)) {
            return;
        }

        $origin = $this->parseOrigin((string) $connection->origin());

        if ($origin === null) {
            throw new InvalidOrigin;
        }

        foreach ($allowedOrigins as $allowedOrigin) {
            $pattern = strtolower(trim((string) $allowedOrigin));

            if ($pattern === '') {
                continue;
            }

            $matched = str_contains($pattern, '://')
                ? Str::is($this->normalizeOriginPattern($pattern), $origin['origin'])
                : Str::is($pattern, $origin['host']);

            if ($matched) {
                return;
            }
        }

        throw new InvalidOrigin;
    }

    /**
     * Split an Origin header into its host and its canonical scheme/host/port form.
     *
     * @return array{host: string, origin: string}|null
     */
    protected function parseOrigin(string $origin): ?array
    {
        $parts = parse_url($origin);

        if ($parts === false) {
            return null;
        }

        $host = $parts['host'] ?? null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : null;
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        $authority = $port === null || $port === $this->defaultPort($scheme)
            ? $host
            : $host.':'.$port;

        return [
            'host' => $host,
            'origin' => $scheme === null ? $authority : $scheme.'://'.$authority,
        ];
    }

    /**
     * Normalize a scheme-carrying allow-list entry for comparison.
     */
    protected function normalizeOriginPattern(string $pattern): string
    {
        [$scheme, $authority] = explode('://', rtrim($pattern, '/'), 2);

        if (preg_match('/^(.*):(\d+)$/', $authority, $matches) === 1
            && (int) $matches[2] === $this->defaultPort($scheme)) {
            $authority = $matches[1];
        }

        return $scheme.'://'.$authority;
    }

    /**
     * Get the default port for the given URI scheme.
     */
    protected function defaultPort(?string $scheme): ?int
    {
        return match ($scheme) {
            'https', 'wss' => 443,
            'http', 'ws' => 80,
            default => null,
        };
    }
}

<?php

namespace Webpatser\Resonate;

use Webpatser\Resonate\Exceptions\InvalidConfiguration;

class Application
{
    /**
     * Create a new application instance.
     */
    public function __construct(
        protected string $id,
        protected string $key,
        protected string $secret,
        protected int $pingInterval,
        protected int $activityTimeout,
        protected array $allowedOrigins,
        protected int $maxMessageSize,
        protected ?int $maxConnections = null,
        protected string $acceptClientEventsFrom = 'members',
        protected ?array $rateLimiting = null,
        protected array $options = [],
    ) {
        static::ensureRateLimitingIsValid($this->rateLimiting, $this->id);
    }

    /**
     * Validate an application's rate limiting block.
     *
     * Enabled rate limiting with a missing `max_attempts` handed `null` to the
     * limiter, which then rejected every connection after its second message:
     * a typo in the config bricked the application with no error anywhere. The
     * same check runs at server boot against the raw config, so the failure
     * lands on the console rather than on a client.
     *
     * @param  array<string, mixed>|null  $rateLimiting
     *
     * @throws InvalidConfiguration
     */
    public static function ensureRateLimitingIsValid(?array $rateLimiting, string $application): void
    {
        if (($rateLimiting['enabled'] ?? false) !== true) {
            return;
        }

        foreach (['max_attempts', 'decay_seconds'] as $key) {
            $value = $rateLimiting[$key] ?? null;

            if (! is_numeric($value) || (int) $value < 1) {
                throw new InvalidConfiguration(
                    "Rate limiting is enabled for application [{$application}] but [{$key}] is not a positive integer."
                );
            }
        }
    }

    /**
     * Get the application ID.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Get the application key.
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * Get the application secret.
     */
    public function secret(): string
    {
        return $this->secret;
    }

    /**
     * Get the allowed origins.
     *
     * @return array<int, string>
     */
    public function allowedOrigins(): array
    {
        return $this->allowedOrigins;
    }

    /**
     * Get the client ping interval in seconds.
     */
    public function pingInterval(): int
    {
        return $this->pingInterval;
    }

    /**
     * Get the activity timeout in seconds.
     */
    public function activityTimeout(): int
    {
        return $this->activityTimeout;
    }

    /**
     * Get the maximum connections allowed for the application.
     */
    public function maxConnections(): ?int
    {
        return $this->maxConnections;
    }

    /**
     * Determine if the application has a maximum connection limit.
     */
    public function hasMaxConnectionLimit(): bool
    {
        return $this->maxConnections !== null;
    }

    /**
     * Get the maximum message size allowed from the client.
     */
    public function maxMessageSize(): int
    {
        return $this->maxMessageSize;
    }

    /**
     * Get who client events are accepted from for the application - either "all", "members", or "none".
     */
    public function acceptClientEventsFrom(): string
    {
        return $this->acceptClientEventsFrom;
    }

    /**
     * Get the rate limiting configuration for the application.
     */
    public function rateLimiting(): ?array
    {
        return $this->rateLimiting;
    }

    /**
     * Determine if the application has rate limiting enabled.
     */
    public function usesRateLimiting(): bool
    {
        return ($this->rateLimiting['enabled'] ?? false) === true;
    }

    /**
     * Get the application options.
     */
    public function options(): ?array
    {
        return $this->options;
    }

    /**
     * Convert the application to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'app_id' => $this->id,
            'key' => $this->key,
            'secret' => $this->secret,
            'ping_interval' => $this->pingInterval,
            'activity_timeout' => $this->activityTimeout,
            'allowed_origins' => $this->allowedOrigins,
            'max_message_size' => $this->maxMessageSize,
            'options' => $this->options,
        ];
    }
}

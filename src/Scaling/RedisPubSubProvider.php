<?php

namespace Webpatser\Resonate\Scaling;

use Fledge\Async\DisposedException;
use Fledge\Async\Future;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisConfig;
use Fledge\Async\Redis\RedisSubscriber;
use Fledge\Async\Redis\RedisSubscription;
use Throwable;
use Webpatser\Resonate\Loggers\Log;
use Webpatser\Resonate\Scaling\Contracts\PubSubIncomingMessageHandler;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;

use function Fledge\Async\async;
use function Fledge\Async\Redis\createRedisClient;
use function Fledge\Async\Redis\createRedisConnector;

/**
 * Bridges broadcasts between Resonate instances over Redis pub/sub.
 *
 * Built on fledge-fiber's async Redis: a {@see RedisClient} publishes JSON
 * envelopes and a {@see RedisSubscriber} feeds incoming messages to the
 * {@see PubSubIncomingMessageHandler}. Reconnection is handled transparently
 * by fledge's `ReconnectingRedisLink`, so unlike Reverb there are no
 * hand-rolled retry timers. The subscribe loop runs in its own fiber; it
 * suspends on each `iterate()`, never blocking the event loop.
 */
class RedisPubSubProvider implements PubSubProvider
{
    /**
     * The Redis client used to publish envelopes.
     */
    protected ?RedisClient $publisher = null;

    /**
     * The Redis subscriber used to receive envelopes.
     */
    protected ?RedisSubscriber $subscriber = null;

    /**
     * The active subscription to the configured channel.
     */
    protected ?RedisSubscription $subscription = null;

    /**
     * The fiber pumping incoming messages to the handler.
     */
    protected ?Future $listener = null;

    /**
     * Create a new Redis pub/sub provider instance.
     *
     * @param  array<string, mixed>  $server  The `reverb.servers.reverb.scaling.server` config.
     */
    public function __construct(
        protected PubSubIncomingMessageHandler $messageHandler,
        protected string $channel,
        protected array $server = [],
    ) {
        //
    }

    /**
     * Connect the publisher and subscriber to Redis.
     */
    public function connect(): void
    {
        $config = $this->makeConfig();

        $this->publisher = createRedisClient($config);
        $this->subscriber = new RedisSubscriber(createRedisConnector($config));

        $this->subscribe();
    }

    /**
     * Disconnect from Redis.
     */
    public function disconnect(): void
    {
        $this->subscription?->unsubscribe();
        $this->publisher?->quit();

        $this->subscription = null;
        $this->subscriber = null;
        $this->publisher = null;
        $this->listener = null;
    }

    /**
     * Subscribe to the configured channel and pump messages to the handler.
     */
    public function subscribe(): void
    {
        $this->subscription = $this->subscriber->subscribe($this->channel);

        $this->listener = async(function (): void {
            try {
                foreach ($this->subscription as $message) {
                    $this->messageHandler->handle($message);
                }
            } catch (DisposedException) {
                // The subscription was unsubscribed during disconnect; expected.
            } catch (Throwable $e) {
                Log::error('Resonate pub/sub subscriber stopped: '.$e->getMessage());
            }
        });
    }

    /**
     * Listen for the given event.
     */
    public function on(string $event, callable $callback): void
    {
        $this->messageHandler->listen($event, $callback);
    }

    /**
     * Listen for the given event.
     *
     * @alias on
     */
    public function listen(string $event, callable $callback): void
    {
        $this->on($event, $callback);
    }

    /**
     * Stop listening for the given event.
     */
    public function stopListening(string $event): void
    {
        $this->messageHandler->stopListening($event);
    }

    /**
     * Publish a payload to the configured channel.
     *
     * @param  array<string, mixed>  $payload
     */
    public function publish(array $payload): void
    {
        $this->publisher?->publish($this->channel, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Build the fledge-fiber Redis configuration from the scaling server config.
     */
    protected function makeConfig(): RedisConfig
    {
        $timeout = (float) ($this->server['timeout'] ?? RedisConfig::DEFAULT_TIMEOUT);

        if (! empty($this->server['url'])) {
            return RedisConfig::fromUri($this->server['url'], $timeout);
        }

        $host = $this->server['host'] ?? '127.0.0.1';
        $port = $this->server['port'] ?? 6379;
        $database = $this->server['database'] ?? 0;

        $userInfo = '';

        if (! empty($this->server['password'])) {
            $userInfo = rawurlencode((string) ($this->server['username'] ?? ''))
                .':'.rawurlencode((string) $this->server['password']).'@';
        }

        return RedisConfig::fromUri(
            sprintf('redis://%s%s:%s/%s', $userInfo, $host, $port, $database),
            $timeout,
        );
    }
}

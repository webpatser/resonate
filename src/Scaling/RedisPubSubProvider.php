<?php

namespace Webpatser\Resonate\Scaling;

use Fledge\Async\DisposedException;
use Fledge\Async\Future;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisConfig;
use Fledge\Async\Redis\RedisSubscriber;
use Fledge\Async\Redis\RedisSubscription;
use Throwable;
use Webpatser\Resonate\Concurrency\SerialQueue;
use Webpatser\Resonate\Loggers\Log;
use Webpatser\Resonate\Scaling\Contracts\PubSubIncomingMessageHandler;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;

use function Fledge\Async\async;
use function Fledge\Async\delay;
use function Fledge\Async\Redis\createRedisClient;
use function Fledge\Async\Redis\createRedisConnector;

/**
 * Bridges broadcasts between Resonate instances over Redis pub/sub.
 *
 * Built on fledge-fiber's async Redis: a {@see RedisClient} publishes JSON
 * envelopes and a {@see RedisSubscriber} feeds incoming messages to the
 * {@see PubSubIncomingMessageHandler}. The subscribe loop runs in its own
 * fiber; it suspends on each `iterate()`, never blocking the event loop.
 *
 * The publisher reconnects transparently through fledge's
 * `ReconnectingRedisLink`. The subscriber does not: `RedisSubscriber` retries
 * once inline, but its `connect()` sits outside its own catch, so a reconnect
 * attempted while Redis is still down (the normal case during a restart)
 * terminates that subscriber permanently. This class therefore owns the retry
 * loop: on any failure that is not a disposal it backs off, builds a *new*
 * subscriber (the old one is terminally stopped) and subscribes again. Without
 * it the node kept publishing but silently never received another broadcast,
 * terminate request or metrics reply until the process was restarted.
 *
 * Envelopes are handled by a {@see SerialQueue} rather than inline in the
 * subscriber fiber. Handling one envelope can suspend (a metrics gather waits
 * for sibling replies, and terminating a user walks its connections), and every
 * suspension inline in the pump was time in which nothing else arriving over
 * Redis was delivered: one congested peer used to hold up all cross-node
 * traffic. The queue's single worker keeps envelopes in the order Redis
 * delivered them, which is what the per-connection queues then preserve on the
 * wire, while `iterate()` keeps draining the Redis connection.
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
     *
     * @var Future<void>|null
     */
    protected ?Future $listener = null;

    /**
     * The envelopes waiting to be handled, and the fiber handling them.
     */
    protected ?SerialQueue $envelopes = null;

    /**
     * Whether disconnect() has been called, ending the resubscribe loop.
     */
    protected bool $stopped = false;

    /**
     * Seconds to wait before the first resubscribe attempt.
     */
    protected const RETRY_BASE_DELAY = 0.5;

    /**
     * Ceiling for the exponential resubscribe backoff, in seconds.
     */
    protected const RETRY_MAX_DELAY = 10.0;

    /**
     * The default number of envelopes allowed to wait for the handler.
     */
    public const DEFAULT_MAX_QUEUED_MESSAGES = 10_000;

    /**
     * Create a new Redis pub/sub provider instance.
     *
     * @param  array<string, mixed>  $server  The `reverb.servers.reverb.scaling.server` config.
     * @param  int  $maxQueuedMessages  Envelopes allowed to wait before further ones are dropped. Zero disables the bound.
     */
    public function __construct(
        protected PubSubIncomingMessageHandler $messageHandler,
        protected string $channel,
        protected array $server = [],
        protected int $maxQueuedMessages = self::DEFAULT_MAX_QUEUED_MESSAGES,
    ) {
        //
    }

    /**
     * Connect the publisher and subscriber to Redis.
     */
    public function connect(): void
    {
        // Calling connect() twice would otherwise strand the previous
        // subscription and leave two listener fibers feeding the same handler,
        // dispatching every broadcast to local clients twice.
        if ($this->subscriber !== null) {
            $this->disconnect();
        }

        $this->stopped = false;

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
        $this->stopped = true;

        $this->subscription?->unsubscribe();
        $this->publisher?->quit();

        // Envelopes still waiting belong to a subscription that is going away,
        // and a discarded queue refuses further pushes, so a listener fiber
        // that has not yet noticed the stop cannot revive it.
        $this->envelopes?->discard();

        $this->subscription = null;
        $this->subscriber = null;
        $this->publisher = null;
        $this->listener = null;
        $this->envelopes = null;
    }

    /**
     * Subscribe to the configured channel and pump messages to the handler.
     *
     * Runs until disconnect(), resubscribing with exponential backoff whenever
     * the connection drops.
     */
    public function subscribe(): void
    {
        $this->listener = async(function (): void {
            $failures = 0;

            while (! $this->isStopped()) {
                try {
                    $this->subscription = $this->openSubscription();

                    $failures = 0;

                    foreach ($this->subscription as $message) {
                        $this->enqueue((string) $message);
                    }
                } catch (DisposedException) {
                    // Unsubscribed during disconnect; expected.
                    return;
                } catch (Throwable $e) {
                    Log::error('Resonate pub/sub subscriber failed: '.$e->getMessage());
                }

                if ($this->isStopped()) {
                    return;
                }

                delay($this->retryDelay($failures++));

                if ($this->isStopped()) {
                    return;
                }

                try {
                    $this->reconnectSubscriber();
                } catch (Throwable $e) {
                    Log::error('Resonate pub/sub reconnect failed: '.$e->getMessage());
                }
            }
        });
    }

    /**
     * Hand an envelope to the handler queue.
     *
     * Returns false when the envelope was dropped because the queue is full.
     * Dropping the newest keeps the accepted envelopes in the order Redis sent
     * them, and a bound has to exist somewhere: a handler slower than the
     * publish rate would otherwise grow this queue until the process dies,
     * which is the same failure the per-connection bound removes.
     */
    protected function enqueue(string $message): bool
    {
        $queue = $this->envelopes ??= new SerialQueue(
            maxSize: $this->maxQueuedMessages,
            onOverflow: fn () => Log::error(
                'Resonate pub/sub dropped an envelope: '.$this->maxQueuedMessages.' already queued.'
            ),
            onError: fn (Throwable $e) => Log::error(
                'Resonate pub/sub message handler failed: '.$e->getMessage()
            ),
        );

        return $queue->push(fn () => $this->messageHandler->handle($message));
    }

    /**
     * Get the queue feeding the message handler, if one has been created.
     */
    public function envelopes(): ?SerialQueue
    {
        return $this->envelopes;
    }

    /**
     * Open a subscription to the configured channel.
     */
    protected function openSubscription(): RedisSubscription
    {
        return $this->subscriber->subscribe($this->channel);
    }

    /**
     * Replace the subscriber after a failure.
     *
     * A `RedisSubscriber` is terminally stopped once its own inline retry has
     * failed, so recovery requires a fresh one rather than reusing this.
     */
    protected function reconnectSubscriber(): void
    {
        $this->subscriber = new RedisSubscriber(createRedisConnector($this->makeConfig()));
    }

    /**
     * Whether the provider has been disconnected.
     *
     * Marked impure because disconnect() can flip this while the listener
     * fiber is suspended, so the result must be re-read after every
     * suspension point rather than remembered.
     *
     * @phpstan-impure
     */
    protected function isStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * Exponential backoff for the given number of consecutive failures.
     */
    protected function retryDelay(int $failures): float
    {
        return min(
            self::RETRY_MAX_DELAY,
            self::RETRY_BASE_DELAY * (2 ** min($failures, 6)),
        );
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
     * Redis answers `PUBLISH` with the number of subscribers it delivered the
     * message to, this node's own subscriber included. `MetricsHandler` treats
     * every other one as a node that owes it a reply, which lets a gather
     * finish as soon as the last sibling answers instead of always paying the
     * full collection window.
     *
     * Caveat: under Redis Cluster the reply counts only the clients attached
     * to the node that handled the command, so a clustered deployment can
     * under-count and complete a gather before a sibling on another cluster
     * node has answered. Regular pub/sub is not cluster-aware in general (the
     * same limitation applies to `PUBSUB NUMSUB`); operators running Resonate
     * across a Redis Cluster should point the scaling connection at a single
     * Redis instance.
     *
     * @param  array<string, mixed>  $payload
     * @return int<0, max>
     */
    public function publish(array $payload): int
    {
        if ($this->publisher === null) {
            // Silently dropping here meant cross-node broadcasts vanished with
            // no exception and no log line whenever publish() ran before
            // connect() or after disconnect().
            Log::error('Resonate pub/sub publish skipped: not connected.');

            return 0;
        }

        return max(0, $this->publisher->publish($this->channel, json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    /**
     * Build the fledge-fiber Redis configuration from the scaling server config.
     *
     * `RedisConfig::fromParameters()` reads the Laravel-shaped connection array
     * directly. This used to assemble a `redis://user:pass@host:port/db` string
     * by hand, and everything a URI cannot carry was dropped on the way: the
     * `tls` / `rediss` scheme, unix socket paths, `read_timeout`, the retry
     * settings, the client name and tcp keepalive. A configured `url` still
     * wins, since that form is a URI to begin with.
     */
    protected function makeConfig(): RedisConfig
    {
        if (! empty($this->server['url'])) {
            return RedisConfig::fromUri(
                (string) $this->server['url'],
                (float) ($this->server['timeout'] ?? RedisConfig::DEFAULT_TIMEOUT),
            );
        }

        return RedisConfig::fromParameters($this->server);
    }
}

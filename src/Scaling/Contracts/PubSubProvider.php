<?php

namespace Webpatser\Resonate\Scaling\Contracts;

/**
 * A pub/sub transport that bridges broadcasts between Resonate instances.
 *
 * Adapted from Reverb's interface: there is no ReactPHP `LoopInterface` (the
 * fledge-fiber Redis client runs on the ambient Revolt loop) and `publish()`
 * returns the delivered-subscriber count rather than a `PromiseInterface`,
 * because fledge calls are fiber-blocking, so the fiber suspends, not the loop.
 */
interface PubSubProvider
{
    /**
     * Connect the publisher and subscriber to the backend.
     */
    public function connect(): void;

    /**
     * Disconnect from the backend.
     */
    public function disconnect(): void;

    /**
     * Subscribe to the configured channel and pump messages to the handler.
     */
    public function subscribe(): void;

    /**
     * Listen for the given event.
     */
    public function on(string $event, callable $callback): void;

    /**
     * Listen for the given event.
     *
     * @alias on
     */
    public function listen(string $event, callable $callback): void;

    /**
     * Stop listening for the given event.
     */
    public function stopListening(string $event): void;

    /**
     * Publish a payload to the configured channel.
     *
     * Returns the number of subscribers the backend delivered the payload to,
     * which includes this node's own subscriber. `MetricsHandler` uses it to
     * know how many sibling replies to expect, so an implementation that
     * cannot report a count must return `0`, which degrades gathering to the
     * full collection window rather than completing early on bad information.
     *
     * @param  array<string, mixed>  $payload
     * @return int<0, max>
     */
    public function publish(array $payload): int;
}

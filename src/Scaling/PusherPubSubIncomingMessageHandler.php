<?php

namespace Webpatser\Resonate\Scaling;

use Throwable;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Loggers\Log;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\EventDispatcher;
use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\Scaling\Contracts\PubSubIncomingMessageHandler;

/**
 * Routes pub/sub envelopes received from sibling Resonate nodes.
 *
 * Ported from Reverb's `PusherPubSubIncomingMessageHandler`, with one locked
 * deviation: Resonate envelopes are pure JSON. Reverb carries the application
 * as a PHP-`serialize()`d `Application` object and `unserialize()`s it (with
 * an allowed-classes guard). Resonate instead carries the application's `id()`
 * string and re-resolves it through the {@see ApplicationProvider}. Nothing in
 * the envelope is ever `serialize()`d; every field is JSON-native.
 *
 * Envelope shapes (all `json_encode`d on the wire):
 *   message:   {"type":"message","application":"<app-id>","payload":{...},"socket_id":"<id>"?}
 *   terminate: {"type":"terminate","application":"<app-id>","payload":{"user_id":"<id>"}}
 *   metrics:   {"type":"metrics","application":"<app-id>","payload":{"key":"<req-id>","type":"<metric>","options":{...}}}
 *              {"type":"metrics","application":"<app-id>","payload":{"key":"<req-id>","metrics":[...]}}  (reply)
 */
class PusherPubSubIncomingMessageHandler implements PubSubIncomingMessageHandler
{
    /**
     * The registered event listeners keyed by event name.
     *
     * @var array<string, array<int, callable>>
     */
    protected array $events = [];

    /**
     * Handle an incoming message from the pub/sub provider.
     *
     * The receive loop must survive a malformed envelope: anything thrown from
     * JSON decoding, application lookup, or dispatch is logged and swallowed
     * so a single bad publisher cannot wedge the subscriber.
     */
    public function handle(string $payload): void
    {
        try {
            $event = json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR);

            $this->processEventListeners($event);

            $application = app(ApplicationProvider::class)->findById($event['application'] ?? '');

            $except = isset($event['socket_id'])
                ? app(ChannelManager::class)->for($application)->findConnection($event['socket_id'])
                : null;

            match ($event['type'] ?? null) {
                'message' => EventDispatcher::dispatchSynchronously(
                    $application,
                    $event['payload'],
                    $except?->connection()
                ),
                'metrics' => app(MetricsHandler::class)->publish([
                    'application' => $application,
                    'payload' => $event['payload'],
                ]),
                'terminate' => collect(app(ChannelManager::class)->for($application)->connections())
                    ->each(function ($connection) use ($event) {
                        if ((string) $connection->data('user_id') === (string) $event['payload']['user_id']) {
                            $connection->disconnect();
                        }
                    }),
                default => null,
            };
        } catch (Throwable $e) {
            Log::error('Pub/sub envelope dropped: '.$e->getMessage());
        }
    }

    /**
     * Process the given event against the registered listeners.
     *
     * @param  array<string, mixed>  $event
     */
    protected function processEventListeners(array $event): void
    {
        foreach ($this->events as $eventName => $listeners) {
            if (($event['type'] ?? null) === $eventName) {
                foreach ($listeners as $listener) {
                    $listener($event);
                }
            }
        }
    }

    /**
     * Listen for the given event.
     */
    public function listen(string $event, callable $callback): void
    {
        $this->events[$event][] = $callback;
    }

    /**
     * Stop listening for the given event.
     */
    public function stopListening(string $event): void
    {
        unset($this->events[$event]);
    }
}

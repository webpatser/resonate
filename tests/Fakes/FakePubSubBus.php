<?php

namespace Webpatser\Resonate\Tests\Fakes;

use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;

/**
 * An in-memory pub/sub bus that behaves like Redis pub/sub.
 *
 * The two properties that matter for the metrics tests are reproduced exactly:
 * a publisher receives its own publications (the subscriber is a separate
 * connection on the same channel), and `publish()` answers with the number of
 * subscribers the envelope was delivered to.
 */
class FakePubSubBus implements PubSubProvider
{
    /**
     * Every envelope published on the bus, in order.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $published = [];

    /**
     * The callbacks that receive every published envelope.
     *
     * @var array<int, callable>
     */
    public array $subscribers = [];

    /**
     * The subscriber count `publish()` reports back.
     *
     * Set independently of the registered subscribers so a node that receives
     * the request but never answers can be simulated.
     */
    public int $receivers = 1;

    public function connect(): void {}

    public function disconnect(): void {}

    public function subscribe(): void {}

    public function on(string $event, callable $callback): void {}

    public function listen(string $event, callable $callback): void {}

    public function stopListening(string $event): void {}

    public function publish(array $payload): int
    {
        $this->published[] = $payload;

        foreach ($this->subscribers as $subscriber) {
            $subscriber($payload);
        }

        return $this->receivers;
    }

    /**
     * Get the envelopes published so far that are metric requests.
     *
     * @return array<int, array<string, mixed>>
     */
    public function requests(): array
    {
        return array_values(array_filter(
            $this->published,
            fn (array $envelope) => isset($envelope['payload']['type']),
        ));
    }

    /**
     * Get the envelopes published so far that are metric replies.
     *
     * @return array<int, array<string, mixed>>
     */
    public function replies(): array
    {
        return array_values(array_filter(
            $this->published,
            fn (array $envelope) => array_key_exists('metrics', $envelope['payload'] ?? []),
        ));
    }
}

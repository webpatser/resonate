<?php

namespace Webpatser\Resonate\Pulse\Recorders;

use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Laravel\Pulse\Pulse;
use Laravel\Pulse\Recorders\Concerns\Sampling;
use Webpatser\Resonate\Events\MessageReceived;
use Webpatser\Resonate\Events\MessageSent;

class ResonateMessages
{
    use Sampling;

    /**
     * The events to listen for.
     *
     * @var list<class-string>
     */
    public array $listen = [
        MessageSent::class,
        MessageReceived::class,
    ];

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected Pulse $pulse,
        protected Repository $config
    ) {
        //
    }

    /**
     * Record the message.
     */
    public function record(MessageSent|MessageReceived $event): void
    {
        if (! $this->shouldSample()) {
            return;
        }

        $this->pulse->record(
            type: 'reverb_message:'.match ($event::class) {
                MessageSent::class => 'sent',
                MessageReceived::class => 'received',
            },
            key: $event->connection->app()->id(),
            timestamp: CarbonImmutable::now()->getTimestamp(),
        )->onlyBuckets()->count();
    }
}

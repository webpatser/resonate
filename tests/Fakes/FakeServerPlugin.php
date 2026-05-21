<?php

namespace Webpatser\Resonate\Tests\Fakes;

use RuntimeException;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Plugins\Contracts\ConnectionLifecycle;
use Webpatser\Resonate\Plugins\Contracts\MessageInterceptor;
use Webpatser\Resonate\Plugins\Contracts\ServerPlugin;
use Webpatser\Resonate\Plugins\Contracts\TickScheduler;
use Webpatser\Resonate\Plugins\MessageDisposition;
use Webpatser\Resonate\Plugins\PluginContext;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;

/**
 * A reference plugin that records every hook it receives, used by the plugin
 * layer tests to assert the wiring without any product-specific behaviour.
 */
class FakeServerPlugin implements ConnectionLifecycle, MessageInterceptor, ServerPlugin, TickScheduler
{
    public bool $booted = false;

    public ?PluginContext $context = null;

    /** @var array<int, string> */
    public array $opened = [];

    /** @var array<int, string> */
    public array $closed = [];

    /** @var array<int, string> */
    public array $subscribed = [];

    /** @var array<int, array<string, mixed>> */
    public array $messages = [];

    public int $tickRuns = 0;

    public function __construct(
        public MessageDisposition $disposition = MessageDisposition::Relay,
        public bool $throwOnMessage = false,
        public bool $throwOnBoot = false,
        public bool $throwOnOpen = false,
    ) {}

    public function boot(PluginContext $context): void
    {
        if ($this->throwOnBoot) {
            throw new RuntimeException('boom');
        }

        $this->booted = true;
        $this->context = $context;
    }

    public function onMessage(Connection $from, array $event): MessageDisposition
    {
        if ($this->throwOnMessage) {
            throw new RuntimeException('boom');
        }

        $this->messages[] = $event;

        return $this->disposition;
    }

    public function onOpen(Connection $connection): void
    {
        if ($this->throwOnOpen) {
            throw new RuntimeException('boom');
        }

        $this->opened[] = $connection->id();
    }

    public function onClose(Connection $connection): void
    {
        $this->closed[] = $connection->id();
    }

    public function onSubscribe(Connection $connection, Channel $channel): void
    {
        $this->subscribed[] = $channel->name();
    }

    public function ticks(): array
    {
        return [
            ['interval' => 1.0, 'callback' => function (): void {
                $this->tickRuns++;
            }],
        ];
    }
}

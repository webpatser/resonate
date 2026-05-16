<?php

namespace Webpatser\Resonate\Scaling\Contracts;

interface PubSubIncomingMessageHandler
{
    /**
     * Handle an incoming message from the pub/sub provider.
     */
    public function handle(string $payload): void;

    /**
     * Listen for the given event.
     */
    public function listen(string $event, callable $callback): void;

    /**
     * Stop listening for the given event.
     */
    public function stopListening(string $event): void;
}

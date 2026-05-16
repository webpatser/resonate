<?php

namespace Webpatser\Resonate\Scaling;

use Webpatser\Resonate\Contracts\ServerProvider;

/**
 * The Resonate server provider.
 *
 * `EventDispatcher`, `MetricsHandler` and `UsersTerminateController` consult
 * this to decide whether to dispatch locally or fan out across instances. It
 * is bound in the container only when `reverb.servers.reverb.scaling.enabled`
 * is true, so an unbound provider (the common single-node case) means
 * "dispatch synchronously".
 */
class ResonateServerProvider extends ServerProvider
{
    /**
     * Indicates whether the server should publish events.
     */
    protected bool $publishesEvents;

    /**
     * Create a new server provider instance.
     *
     * @param  array<string, mixed>  $config  The `reverb.servers.reverb` config.
     */
    public function __construct(protected array $config)
    {
        $this->publishesEvents = (bool) ($config['scaling']['enabled'] ?? false);
    }

    /**
     * Enable publishing of events.
     */
    public function withPublishing(): void
    {
        $this->publishesEvents = true;
    }

    /**
     * Determine whether the server should publish events.
     */
    public function shouldPublishEvents(): bool
    {
        return $this->publishesEvents;
    }
}

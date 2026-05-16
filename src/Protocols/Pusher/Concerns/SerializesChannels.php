<?php

namespace Webpatser\Resonate\Protocols\Pusher\Concerns;

use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelConnectionManager;

trait SerializesChannels
{
    /**
     * Prepare the channel instance values for serialization.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'name' => $this->name,
        ];
    }

    /**
     * Restore the channel after serialization.
     */
    public function __unserialize(array $values): void
    {
        $this->name = $values['name'];
        $this->connections = app(ChannelConnectionManager::class)->for($this->name);
    }
}

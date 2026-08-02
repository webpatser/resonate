<?php

namespace Webpatser\Resonate;

use Illuminate\Support\Manager;

class ApplicationManager extends Manager
{
    /**
     * Create an instance of the configuration driver.
     */
    public function createConfigDriver(): ConfigApplicationProvider
    {
        /** @var array<array-key, array<string, mixed>> $apps */
        $apps = $this->config->get('reverb.apps.apps', []);

        return new ConfigApplicationProvider(collect($apps));
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('reverb.apps.provider', 'config');
    }
}

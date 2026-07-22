<?php

namespace Webpatser\Resonate;

use Illuminate\Foundation\DevCommands;

class Resonate
{
    /**
     * Register the Resonate dev commands.
     */
    public static function registerDevCommands(): void
    {
        if (class_exists(DevCommands::class)) {
            DevCommands::artisan('resonate:start', 'resonate');
        }
    }
}

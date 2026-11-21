<?php

/*
 * Runs `php artisan resonate:start` in a process of its own, the way a host
 * application does, for the tests that need a real server process: signals,
 * the event loop driver choice and descriptor counts above FD_SETSIZE.
 *
 * Everything comes from the environment through config/reverb.php
 * (REVERB_SERVER_HOST, REVERB_SERVER_PORT, REVERB_APP_KEY, REVERB_APP_SECRET,
 * REVERB_APP_ID, FLEDGE_EVENT_LOOP), plus RESONATE_TEST_STORAGE, the storage
 * directory that receives the PID and runtime files.
 */

use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\Foundation\Application;
use Symfony\Component\Console\Output\StreamOutput;
use Webpatser\Resonate\ResonateServiceProvider;

require __DIR__.'/../../vendor/autoload.php';

$storage = getenv('RESONATE_TEST_STORAGE');

if (! is_string($storage) || ! is_dir($storage)) {
    fwrite(STDERR, "RESONATE_TEST_STORAGE must name an existing directory.\n");

    exit(2);
}

$app = Application::create(options: ['extra' => [
    'providers' => [ResonateServiceProvider::class],
    'dont-discover' => ['*'],
]]);

$app->useStoragePath($storage);

exit($app->make(Kernel::class)->call('resonate:start', [], new StreamOutput(STDOUT)));

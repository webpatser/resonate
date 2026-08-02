<?php

use Illuminate\Support\Facades\Artisan;
use Webpatser\Resonate\Console\Commands\StartServer;

/*
 * Regression: an application with rate limiting enabled but no `max_attempts`
 * (or no `decay_seconds`) started happily and then rejected every message after
 * the second one, because `tooManyAttempts($key, null)` treats any count as
 * over the limit. The config is validated at boot instead, so the failure lands
 * on the console with the offending key named.
 *
 * Only the refusal path is exercised here: the success path would block on a
 * real event loop.
 */

beforeEach(function () {
    removeStartServerRuntimeFiles();
});

afterEach(function () {
    removeStartServerRuntimeFiles();
});

function removeStartServerRuntimeFiles(): void
{
    foreach ([StartServer::pidFilePath(), StartServer::runtimeFilePath()] as $path) {
        if (file_exists($path) && ! is_link($path)) {
            @unlink($path);
        }
    }
}

it('refuses to start when enabled rate limiting has no max_attempts', function () {
    $this->app['config']->set('reverb.apps.apps.0.rate_limiting', [
        'enabled' => true,
        'decay_seconds' => 60,
    ]);

    $exit = Artisan::call('resonate:start');

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('max_attempts');
});

it('refuses to start when enabled rate limiting has a zero decay_seconds', function () {
    $this->app['config']->set('reverb.apps.apps.0.rate_limiting', [
        'enabled' => true,
        'max_attempts' => 60,
        'decay_seconds' => 0,
    ]);

    $exit = Artisan::call('resonate:start');

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('decay_seconds');
});

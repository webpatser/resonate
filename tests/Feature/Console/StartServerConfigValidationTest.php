<?php

use Fledge\Loop;
use Fledge\Loop\Driver\PollDriver;
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

it('refuses to start when a trusted proxy entry is not a CIDR range', function () {
    $this->app['config']->set('reverb.servers.reverb.trusted_proxies', ['10.0.0.0/8', 'proxy.internal']);

    $exit = Artisan::call('resonate:start');

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('trusted_proxies');
});

/**
 * Run a callback with FLEDGE_EVENT_LOOP set (or unset with null), restoring it after.
 */
function withEventLoopEnvironment(?string $value, Closure $callback): mixed
{
    $previous = getenv('FLEDGE_EVENT_LOOP');

    putenv($value === null ? 'FLEDGE_EVENT_LOOP' : "FLEDGE_EVENT_LOOP={$value}");

    try {
        return $callback();
    } finally {
        putenv($previous === false ? 'FLEDGE_EVENT_LOOP' : "FLEDGE_EVENT_LOOP={$previous}");
    }
}

it('refuses to start with an unknown event loop driver in the config', function () {
    $this->app['config']->set('reverb.servers.reverb.event_loop', 'libuv');

    $exit = withEventLoopEnvironment(null, fn () => Artisan::call('resonate:start'));

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('"libuv" from servers.reverb.event_loop')
        ->toContain('auto, poll, select');
});

it('names FLEDGE_EVENT_LOOP when the environment holds an unknown driver', function () {
    $this->app['config']->set('reverb.servers.reverb.event_loop', 'auto');

    $exit = withEventLoopEnvironment('libuv', fn () => Artisan::call('resonate:start'));

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('"libuv" from FLEDGE_EVENT_LOOP in the environment')
        ->toContain('auto, poll, select');
});

it('lets FLEDGE_EVENT_LOOP in the environment override a cached event_loop setting', function () {
    $this->app['config']->set('reverb.servers.reverb.event_loop', 'libuv');
    $this->app['config']->set('reverb.servers.reverb.tcp_keepalive', 'often');

    // The environment's valid driver wins over the invalid setting, so the
    // command gets as far as the next check and refuses there instead. It
    // names the driver the suite already runs on, so nothing is swapped.
    $current = Loop::getDriver() instanceof PollDriver ? 'poll' : 'select';

    $exit = withEventLoopEnvironment($current, fn () => Artisan::call('resonate:start'));

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain("FLEDGE_EVENT_LOOP={$current} overrides servers.reverb.event_loop=libuv")
        ->toContain('tcp_keepalive');
});

it('refuses to start with an invalid tcp_keepalive value', function () {
    $this->app['config']->set('reverb.servers.reverb.tcp_keepalive', 'often');

    $exit = Artisan::call('resonate:start');

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('tcp_keepalive');
});

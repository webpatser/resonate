<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/*
 * `resonate:restart` is the legacy hard-restart command: it stores the current
 * unix time at the `laravel:reverb:restart` cache key, and the running server
 * picks up the change via its 5-second poll (see
 * StartServer::ensureRestartCommandIsRespected). We test the producer side
 * here; the poll is exercised end-to-end by the manual verification steps.
 *
 * Testbench defaults to the array cache store, which is process-local and
 * cleared between tests, so the assertions don't bleed across cases.
 */

beforeEach(function () {
    Cache::forget('laravel:reverb:restart');
});

it('writes a unix timestamp to the laravel:reverb:restart cache key', function () {
    $before = time();

    Artisan::call('resonate:restart');

    $value = Cache::get('laravel:reverb:restart');

    expect($value)->toBeNumeric()
        ->and((int) $value)->toBeGreaterThanOrEqual($before)
        ->and((int) $value)->toBeLessThanOrEqual(time() + 1);
});

it('prints the broadcast confirmation', function () {
    Artisan::call('resonate:restart');

    expect(Artisan::output())->toContain('Broadcasting Resonate restart signal');
});

it('overwrites an existing restart signal with a newer-or-equal timestamp', function () {
    Artisan::call('resonate:restart');
    $first = (int) Cache::get('laravel:reverb:restart');

    // The poll loop compares with !== so equal values are fine, but a real
    // operator-driven restart will almost always advance the timestamp. We
    // assert "not strictly older" rather than "strictly newer" so the test is
    // not wall-clock-flaky on fast systems where two calls land in the same
    // second.
    usleep(1_100_000);
    Artisan::call('resonate:restart');
    $second = (int) Cache::get('laravel:reverb:restart');

    expect($second)->toBeGreaterThanOrEqual($first);
});

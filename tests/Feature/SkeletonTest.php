<?php

use Webpatser\Resonate\ResonateServiceProvider;

it('boots the service provider', function () {
    expect(app()->getProviders(ResonateServiceProvider::class))->not->toBeEmpty();
});

it('merges the reverb config', function () {
    expect(config('reverb.default'))->toBe('reverb')
        ->and(config('reverb.servers.reverb.port'))->toBe(8080)
        ->and(config('reverb.apps.apps.0.key'))->toBe('app-key');
});

<?php

use Illuminate\Support\Collection;
use Webpatser\Resonate\ConfigApplicationProvider;

/*
 * An app config that omits `accept_client_events_from` used to fall back to
 * 'all' here, while Application and config/reverb.php default to 'members'.
 * The provider is what builds every configured app, so the stricter default
 * has to hold here too.
 */

function configProviderFor(array $overrides = []): ConfigApplicationProvider
{
    // No accept_client_events_from key unless an override sets one.
    $app = array_merge([
        'app_id' => 'app-id',
        'key' => 'app-key',
        'secret' => 'app-secret',
        'ping_interval' => 60,
        'allowed_origins' => ['*'],
        'max_message_size' => 10_000,
    ], $overrides);

    return new ConfigApplicationProvider(Collection::make([$app]));
}

it('defaults accept_client_events_from to members when the key is missing', function () {
    $application = configProviderFor()->findById('app-id');

    expect($application->acceptClientEventsFrom())->toBe('members');
});

it('defaults accept_client_events_from to members when the key is null', function () {
    $application = configProviderFor(['accept_client_events_from' => null])->findByKey('app-key');

    expect($application->acceptClientEventsFrom())->toBe('members');
});

it('keeps an explicitly configured accept_client_events_from', function (string $mode) {
    $application = configProviderFor(['accept_client_events_from' => $mode])->findById('app-id');

    expect($application->acceptClientEventsFrom())->toBe($mode);
})->with(['all', 'members', 'none']);

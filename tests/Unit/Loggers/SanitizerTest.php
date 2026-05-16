<?php

use Webpatser\Resonate\Loggers\Sanitizer;

it('redacts the private-channel auth token from a subscribe frame', function () {
    $message = [
        'event' => 'pusher:subscribe',
        'data' => [
            'channel' => 'private-orders',
            'auth' => 'app-key:'.str_repeat('a', 64),
        ],
    ];

    $redacted = Sanitizer::redact($message);

    expect($redacted['data']['auth'])->toBe('[redacted]')
        ->and(json_encode($redacted))->not->toContain(str_repeat('a', 64));
});

it('redacts presence channel_data', function () {
    $message = [
        'event' => 'pusher:subscribe',
        'data' => [
            'channel' => 'presence-room',
            'auth' => 'app-key:deadbeef',
            'channel_data' => ['user_id' => '1', 'user_info' => ['email' => 'leak@example.com']],
        ],
    ];

    $redacted = Sanitizer::redact($message);

    expect($redacted['data']['auth'])->toBe('[redacted]')
        ->and($redacted['data']['channel_data'])->toBe('[redacted: presence channel_data]')
        ->and(json_encode($redacted))->not->toContain('leak@example.com');
});

it('passes a message through unchanged when there is no sensitive data', function () {
    $message = [
        'event' => 'pusher:subscribe',
        'data' => ['channel' => 'public-channel'],
    ];

    expect(Sanitizer::redact($message))->toBe($message);
});

it('handles a message without an array data field', function () {
    $message = ['event' => 'pusher:ping', 'data' => 'something'];

    expect(Sanitizer::redact($message))->toBe($message);
});

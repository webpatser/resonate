<?php

use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\EventsController;
use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\Feature\Protocols\Pusher\Http\RequestSigner;

/*
 * Exercises the EventsController (POST /apps/{appId}/events) directly: it
 * builds a signed fledge request, invokes handleRequest(), and asserts on
 * the returned response plus the messages that actually reach subscribed
 * connections. Ported from Reverb's EventsControllerTest, adapted from
 * real-HTTP-against-a-booted-server to direct controller invocation.
 */

it('can receive an event trigger', function () {
    $response = (new EventsController)->handleRequest(RequestSigner::post('/apps/app-id/events', [
        'name' => 'NewEvent',
        'channel' => 'test-channel',
        'data' => json_encode(['some' => 'data']),
    ]));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{}');
});

it('can receive an event trigger for multiple channels', function () {
    $response = (new EventsController)->handleRequest(RequestSigner::post('/apps/app-id/events', [
        'name' => 'NewEvent',
        'channels' => ['test-channel-one', 'test-channel-two'],
        'data' => json_encode(['some' => 'data']),
    ]));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{}');
});

it('delivers the event to subscribed connections', function () {
    $connection = new FakeConnection;
    RequestSigner::subscribe($connection, 'test-channel');

    $response = (new EventsController)->handleRequest(RequestSigner::post('/apps/app-id/events', [
        'name' => 'NewEvent',
        'channel' => 'test-channel',
        'data' => json_encode(['some' => 'data']),
    ]));

    expect($response->getStatus())->toBe(200)
        ->and($connection->messages)->toContain(
            '{"event":"NewEvent","data":"{\"some\":\"data\"}","channel":"test-channel"}'
        );
});

it('can ignore a subscriber via socket_id', function () {
    $ignored = new FakeConnection;
    $other = new FakeConnection;
    RequestSigner::subscribe($ignored, 'test-channel');
    RequestSigner::subscribe($other, 'test-channel');

    $response = (new EventsController)->handleRequest(RequestSigner::post('/apps/app-id/events', [
        'name' => 'NewEvent',
        'channels' => ['test-channel'],
        'data' => json_encode(['some' => 'data']),
        'socket_id' => $ignored->id(),
    ]));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{}')
        ->and($ignored->messages)->toBeEmpty()
        ->and($other->messages)->toHaveCount(1);
});

it('does not fail when ignoring an invalid subscriber', function () {
    $connection = new FakeConnection;
    RequestSigner::subscribe($connection, 'test-channel');

    $response = (new EventsController)->handleRequest(RequestSigner::post('/apps/app-id/events', [
        'name' => 'NewEvent',
        'channels' => ['test-channel'],
        'data' => json_encode(['some' => 'data']),
        'socket_id' => 'invalid-socket-id',
    ]));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{}')
        ->and($connection->messages)->toHaveCount(1);
});

it('validates invalid data', function ($payload) {
    $response = (new EventsController)->handleRequest(RequestSigner::post('/apps/app-id/events', $payload));

    expect($response->getStatus())->toBe(422);
})->with([
    'missing data' => [[
        'name' => 'NewEvent',
        'channel' => 'test-channel',
    ]],
    'missing data with channels' => [[
        'name' => 'NewEvent',
        'channels' => ['test-channel-one', 'test-channel-two'],
    ]],
    'non-string socket_id' => [[
        'name' => 'NewEvent',
        'channel' => 'test-channel',
        'data' => json_encode(['some' => 'data']),
        'socket_id' => 1234,
    ]],
    'non-string info' => [[
        'name' => 'NewEvent',
        'channel' => 'test-channel',
        'data' => json_encode(['some' => 'data']),
        'info' => 1234,
    ]],
]);

it('fails with 500 when the payload is invalid json', function () {
    $response = (new EventsController)->handleRequest(RequestSigner::signed('POST', '/apps/app-id/events', 'not-json'));

    expect($response->getStatus())->toBe(500);
});

it('fails with 404 when the app cannot be found', function () {
    $response = (new EventsController)->handleRequest(
        RequestSigner::post('/apps/invalid-app-id/events', [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
        ], routeParams: ['appId' => 'invalid-app-id'])
    );

    expect($response->getStatus())->toBe(404);
});

it('fails with 401 when using an invalid signature', function () {
    $response = (new EventsController)->handleRequest(RequestSigner::unsigned('/apps/app-id/events', [
        'name' => 'NewEvent',
        'channel' => 'test-channel',
        'data' => json_encode(['some' => 'data']),
    ]));

    expect($response->getStatus())->toBe(401);
});

it('can return user counts when requested', function () {
    $connection = new FakeConnection;
    RequestSigner::subscribe($connection, 'presence-test-channel-one');

    $response = (new EventsController)->handleRequest(RequestSigner::post('/apps/app-id/events', [
        'name' => 'NewEvent',
        'channels' => ['presence-test-channel-one', 'test-channel-two'],
        'data' => json_encode(['some' => 'data']),
        'info' => 'user_count',
    ]));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe(
            '{"channels":{"presence-test-channel-one":{"user_count":1},"test-channel-two":{}}}'
        );
})->skip(fn () => ! class_exists(MetricsHandler::class), 'MetricsHandler (module 3C) not yet available.');

it('can return subscription counts when requested', function () {
    $connection = new FakeConnection;
    RequestSigner::subscribe($connection, 'test-channel-two');

    $response = (new EventsController)->handleRequest(RequestSigner::post('/apps/app-id/events', [
        'name' => 'NewEvent',
        'channels' => ['presence-test-channel-one', 'test-channel-two'],
        'data' => json_encode(['some' => 'data']),
        'info' => 'subscription_count',
    ]));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe(
            '{"channels":{"presence-test-channel-one":{},"test-channel-two":{"subscription_count":1}}}'
        );
})->skip(fn () => ! class_exists(MetricsHandler::class), 'MetricsHandler (module 3C) not yet available.');

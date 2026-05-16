<?php

use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\EventsBatchController;
use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\Feature\Protocols\Pusher\Http\RequestSigner;

/*
 * Exercises the EventsBatchController (POST /apps/{appId}/batch_events)
 * directly. Ported from Reverb's EventsBatchControllerTest, adapted from
 * real-HTTP-against-a-booted-server to direct controller invocation.
 */

it('can receive an event batch trigger', function () {
    $response = (new EventsBatchController)->handleRequest(RequestSigner::post('/apps/app-id/batch_events', [
        'batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'test-channel',
                'data' => json_encode(['some' => 'data']),
            ],
        ],
    ]));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"batch":{}}');
});

it('can receive an event batch trigger with multiple events', function () {
    $response = (new EventsBatchController)->handleRequest(RequestSigner::post('/apps/app-id/batch_events', [
        'batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'test-channel',
                'data' => json_encode(['some' => 'data']),
            ],
            [
                'name' => 'AnotherNewEvent',
                'channel' => 'test-channel-two',
                'data' => json_encode(['some' => ['more' => 'data']]),
            ],
        ],
    ]));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"batch":{}}');
});

it('delivers each event in the batch to subscribed connections', function () {
    $one = new FakeConnection;
    $two = new FakeConnection;
    RequestSigner::subscribe($one, 'test-channel');
    RequestSigner::subscribe($two, 'test-channel-two');

    $response = (new EventsBatchController)->handleRequest(RequestSigner::post('/apps/app-id/batch_events', [
        'batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'test-channel',
                'data' => json_encode(['some' => 'data']),
            ],
            [
                'name' => 'AnotherNewEvent',
                'channel' => 'test-channel-two',
                'data' => json_encode(['some' => ['more' => 'data']]),
            ],
        ],
    ]));

    expect($response->getStatus())->toBe(200)
        ->and($one->messages)->toContain(
            '{"event":"NewEvent","channel":"test-channel","data":"{\"some\":\"data\"}"}'
        )
        ->and($two->messages)->toContain(
            '{"event":"AnotherNewEvent","channel":"test-channel-two","data":"{\"some\":{\"more\":\"data\"}}"}'
        );
});

it('can ignore a subscriber via socket_id in a batch item', function () {
    $ignored = new FakeConnection;
    $other = new FakeConnection;
    RequestSigner::subscribe($ignored, 'test-channel');
    RequestSigner::subscribe($other, 'test-channel');

    $response = (new EventsBatchController)->handleRequest(RequestSigner::post('/apps/app-id/batch_events', [
        'batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'test-channel',
                'data' => json_encode(['some' => 'data']),
                'socket_id' => $ignored->id(),
            ],
        ],
    ]));

    expect($response->getStatus())->toBe(200)
        ->and($ignored->messages)->toBeEmpty()
        ->and($other->messages)->toHaveCount(1);
});

it('validates an invalid batch payload', function () {
    $response = (new EventsBatchController)->handleRequest(RequestSigner::post('/apps/app-id/batch_events', [
        'batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'test-channel',
            ],
        ],
    ]));

    expect($response->getStatus())->toBe(422);
});

it('fails with 500 when the batch payload is invalid json', function () {
    $response = (new EventsBatchController)->handleRequest(
        RequestSigner::signed('POST', '/apps/app-id/batch_events', 'not-json')
    );

    expect($response->getStatus())->toBe(500);
});

it('fails with 401 when using an invalid signature', function () {
    $response = (new EventsBatchController)->handleRequest(RequestSigner::unsigned('/apps/app-id/batch_events', [
        'batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'test-channel',
                'data' => json_encode(['some' => 'data']),
            ],
        ],
    ]));

    expect($response->getStatus())->toBe(401);
});

it('can receive a batch trigger with multiple events and return info for each', function () {
    RequestSigner::subscribe(new FakeConnection, 'presence-test-channel');
    RequestSigner::subscribe(new FakeConnection, 'test-channel-two');
    RequestSigner::subscribe(new FakeConnection, 'test-channel-three');

    $response = (new EventsBatchController)->handleRequest(RequestSigner::post('/apps/app-id/batch_events', [
        'batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'presence-test-channel',
                'data' => json_encode(['some' => 'data']),
                'info' => 'user_count',
            ],
            [
                'name' => 'AnotherNewEvent',
                'channel' => 'test-channel-two',
                'data' => json_encode(['some' => ['more' => 'data']]),
                'info' => 'subscription_count',
            ],
            [
                'name' => 'YetAnotherNewEvent',
                'channel' => 'test-channel-three',
                'data' => json_encode(['some' => ['more' => 'data']]),
                'info' => 'subscription_count,user_count',
            ],
        ],
    ]));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe(
            '{"batch":[{"user_count":1},{"subscription_count":1},{"subscription_count":1}]}'
        );
})->skip(fn () => ! class_exists(MetricsHandler::class), 'MetricsHandler (module 3C) not yet available.');

it('can receive a batch trigger with multiple events and return info for some', function () {
    RequestSigner::subscribe(new FakeConnection, 'presence-test-channel');

    $response = (new EventsBatchController)->handleRequest(RequestSigner::post('/apps/app-id/batch_events', [
        'batch' => [
            [
                'name' => 'NewEvent',
                'channel' => 'presence-test-channel',
                'data' => json_encode(['some' => 'data']),
                'info' => 'user_count',
            ],
            [
                'name' => 'AnotherNewEvent',
                'channel' => 'test-channel-two',
                'data' => json_encode(['some' => ['more' => 'data']]),
            ],
        ],
    ]));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"batch":[{"user_count":1},{}]}');
})->skip(fn () => ! class_exists(MetricsHandler::class), 'MetricsHandler (module 3C) not yet available.');

<?php

use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\ChannelController;
use Webpatser\Resonate\Server\Router;
use Webpatser\Resonate\Tests\Feature\Protocols\Pusher\Http\RequestSigner;

/*
 * Exercises ChannelController: GET /apps/{appId}/channels/{channel}.
 * Ported from Reverb's ChannelControllerTest. The shared signedChannelRequest()
 * and subscribeToChannel() helpers are defined in ChannelsControllerTest.php.
 */

it('can return data for a single channel', function () {
    subscribeToChannel('test-channel-one');
    subscribeToChannel('test-channel-one');

    $response = (new ChannelController)->handleRequest(signedChannelRequest(
        'GET',
        '/apps/app-id/channels/test-channel-one',
        ['info' => 'user_count,subscription_count,cache'],
        routeParams: ['appId' => 'app-id', 'channel' => 'test-channel-one'],
    ));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"occupied":true,"subscription_count":2}');
});

it('returns unoccupied when no connections', function () {
    $response = (new ChannelController)->handleRequest(signedChannelRequest(
        'GET',
        '/apps/app-id/channels/test-channel-one',
        ['info' => 'user_count,subscription_count,cache'],
        routeParams: ['appId' => 'app-id', 'channel' => 'test-channel-one'],
    ));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"occupied":false}');
});

it('can return cache channel attributes', function () {
    subscribeToChannel('cache-test-channel-one');
    channels()->find('cache-test-channel-one')->broadcast(['some' => 'data']);

    $response = (new ChannelController)->handleRequest(signedChannelRequest(
        'GET',
        '/apps/app-id/channels/cache-test-channel-one',
        ['info' => 'subscription_count,cache'],
        routeParams: ['appId' => 'app-id', 'channel' => 'cache-test-channel-one'],
    ));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"occupied":true,"subscription_count":1,"cache":{"some":"data"}}');
});

it('can return presence channel attributes', function () {
    subscribeToChannel('presence-test-channel-one', ['user_id' => 123]);
    subscribeToChannel('presence-test-channel-one', ['user_id' => 123]);

    $response = (new ChannelController)->handleRequest(signedChannelRequest(
        'GET',
        '/apps/app-id/channels/presence-test-channel-one',
        ['info' => 'user_count,subscription_count,cache'],
        routeParams: ['appId' => 'app-id', 'channel' => 'presence-test-channel-one'],
    ));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"occupied":true,"user_count":1}');
});

it('can return only the requested attributes', function () {
    subscribeToChannel('test-channel-one');

    $response = (new ChannelController)->handleRequest(signedChannelRequest(
        'GET',
        '/apps/app-id/channels/test-channel-one',
        ['info' => 'cache'],
        routeParams: ['appId' => 'app-id', 'channel' => 'test-channel-one'],
    ));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"occupied":true}');
});

it('fails when using an invalid signature', function () {
    $request = new Fledge\Async\Http\Server\Request(
        Mockery::mock(Fledge\Async\Http\Server\Driver\Client::class),
        'GET',
        League\Uri\Http::new('http://localhost/apps/app-id/channels/test-channel-one?auth_signature=deadbeef'),
    );
    $request->setAttribute(Router::class, ['appId' => 'app-id', 'channel' => 'test-channel-one']);

    $response = (new ChannelController)->handleRequest($request);

    expect($response->getStatus())->toBe(401);
});

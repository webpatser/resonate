<?php

use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\ConnectionsController;
use Webpatser\Resonate\Server\Router;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\Feature\Protocols\Pusher\Http\RequestSigner;

/*
 * Exercises ConnectionsController: GET /apps/{appId}/connections. Ported from
 * Reverb's ConnectionsControllerTest. The shared signedChannelRequest() and
 * subscribeToChannel() helpers are defined in ChannelsControllerTest.php.
 */

it('can return a connection count', function () {
    subscribeToChannel('test-channel-one');
    subscribeToChannel('presence-test-channel-two', ['user_id' => 1]);

    $response = (new ConnectionsController)->handleRequest(signedChannelRequest('GET', '/apps/app-id/connections'));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"connections":2}');
});

it('can return the correct connection count when subscribed to multiple channels', function () {
    $connection = new FakeConnection;

    channels()->findOrCreate('test-channel-one')->subscribe($connection);
    channels()->findOrCreate('test-channel-two')->subscribe($connection);

    $response = (new ConnectionsController)->handleRequest(signedChannelRequest('GET', '/apps/app-id/connections'));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"connections":1}');
});

it('fails when using an invalid signature', function () {
    $request = new Fledge\Async\Http\Server\Request(
        Mockery::mock(Fledge\Async\Http\Server\Driver\Client::class),
        'GET',
        League\Uri\Http::new('http://localhost/apps/app-id/connections?auth_signature=deadbeef'),
    );
    $request->setAttribute(Router::class, ['appId' => 'app-id']);

    $response = (new ConnectionsController)->handleRequest($request);

    expect($response->getStatus())->toBe(401);
});

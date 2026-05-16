<?php

use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\ChannelUsersController;
use Webpatser\Resonate\Server\Router;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\Feature\Protocols\Pusher\Http\RequestSigner;

/*
 * Exercises ChannelUsersController: GET /apps/{appId}/channels/{channel}/users.
 * Ported from Reverb's ChannelUsersControllerTest. The shared
 * signedChannelRequest() helper is defined in ChannelsControllerTest.php.
 */

it('returns an error when presence channel not provided', function () {
    subscribeToChannel('test-channel');

    $response = (new ChannelUsersController)->handleRequest(signedChannelRequest(
        'GET',
        '/apps/app-id/channels/test-channel/users',
        routeParams: ['appId' => 'app-id', 'channel' => 'test-channel'],
    ));

    expect($response->getStatus())->toBe(400);
});

it('returns an error when unoccupied channel provided', function () {
    $response = (new ChannelUsersController)->handleRequest(signedChannelRequest(
        'GET',
        '/apps/app-id/channels/presence-test-channel/users',
        routeParams: ['appId' => 'app-id', 'channel' => 'presence-test-channel'],
    ));

    expect($response->getStatus())->toBe(404);
});

it('returns the user data', function () {
    $channel = channels()->findOrCreate('presence-test-channel');

    foreach ([1, 2, 3] as $id) {
        $connection = new FakeConnection;
        $data = json_encode(['user_id' => $id, 'user_info' => ['name' => "User {$id}"]]);
        $channel->subscribe($connection, validAuth($connection->id(), 'presence-test-channel', $data), $data);
    }

    $response = (new ChannelUsersController)->handleRequest(signedChannelRequest(
        'GET',
        '/apps/app-id/channels/presence-test-channel/users',
        routeParams: ['appId' => 'app-id', 'channel' => 'presence-test-channel'],
    ));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"users":[{"id":1},{"id":2},{"id":3}]}');
});

it('returns the unique user data', function () {
    $channel = channels()->findOrCreate('presence-test-channel');

    foreach ([3, 2, 3] as $id) {
        $connection = new FakeConnection;
        $data = json_encode(['user_id' => $id, 'user_info' => ['name' => "User {$id}"]]);
        $channel->subscribe($connection, validAuth($connection->id(), 'presence-test-channel', $data), $data);
    }

    $response = (new ChannelUsersController)->handleRequest(signedChannelRequest(
        'GET',
        '/apps/app-id/channels/presence-test-channel/users',
        routeParams: ['appId' => 'app-id', 'channel' => 'presence-test-channel'],
    ));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"users":[{"id":3},{"id":2}]}');
});

it('fails when using an invalid signature', function () {
    $request = new Fledge\Async\Http\Server\Request(
        Mockery::mock(Fledge\Async\Http\Server\Driver\Client::class),
        'GET',
        League\Uri\Http::new('http://localhost/apps/app-id/channels/presence-test-channel/users?auth_signature=deadbeef'),
    );
    $request->setAttribute(Router::class, ['appId' => 'app-id', 'channel' => 'presence-test-channel']);

    $response = (new ChannelUsersController)->handleRequest($request);

    expect($response->getStatus())->toBe(401);
});

<?php

use Fledge\Async\Http\Server\Driver\Client;
use Fledge\Async\Http\Server\Request;
use League\Uri\Http;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\ChannelUsersController;
use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;
use Webpatser\Resonate\Server\Router;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\Fakes\FakePubSubBus;
use Webpatser\Resonate\Tests\Fakes\ScaledServerProvider;
use Webpatser\Resonate\Tests\Feature\Protocols\Pusher\Http\RequestSigner;

/*
 * Exercises ChannelUsersController: GET /apps/{appId}/channels/{channel}/users.
 * Ported from Reverb's ChannelUsersControllerTest. The shared
 * signedChannelRequest() helper is defined in tests/Pest.php.
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

/*
 * Scaled: the presence channel can have every member on another node, so the
 * answering node must not 404 on its own registry miss before it gathers.
 */

/**
 * Turn this test's server into one node of a scaled fleet whose single
 * sibling answers every metrics request with the given set.
 *
 * @param  array<int, mixed>  $siblingMetrics
 */
function scaleChannelUsersFleet(array $siblingMetrics): FakePubSubBus
{
    $bus = new FakePubSubBus;
    $bus->receivers = 2; // this node's own subscriber plus the sibling

    app()->instance(PubSubProvider::class, $bus);
    app()->instance(ServerProvider::class, new ScaledServerProvider);
    app()->instance(MetricsHandler::class, $handler = new MetricsHandler(app(ChannelManager::class), 5.0));

    $application = app(ApplicationProvider::class)->findById('app-id');

    // This node's own subscriber: replies reach the handler through it.
    $bus->subscribers[] = fn (array $envelope) => $handler->publish([
        'application' => $application,
        'payload' => $envelope['payload'],
    ]);

    // The sibling holds members of the channel only for channel_users; any
    // other metric (presence subscribes gather too) it answers as empty.
    $bus->subscribers[] = function (array $envelope) use ($bus, $siblingMetrics) {
        if (! isset($envelope['payload']['type'])) {
            return;
        }

        if ($envelope['payload']['type'] !== 'channel_users') {
            $siblingMetrics = [];
        }

        $bus->publish([
            'type' => 'metrics',
            'application' => 'app-id',
            'payload' => [
                'key' => $envelope['payload']['key'],
                'node' => 'sibling-node',
                'metrics' => $siblingMetrics,
            ],
        ]);
    };

    return $bus;
}

it('returns the users of a presence channel whose members are all on another node', function () {
    scaleChannelUsersFleet([['id' => 7], ['id' => 8]]);

    $response = (new ChannelUsersController)->handleRequest(signedChannelRequest(
        'GET',
        '/apps/app-id/channels/presence-test-channel/users',
        routeParams: ['appId' => 'app-id', 'channel' => 'presence-test-channel'],
    ));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"users":[{"id":7},{"id":8}]}');
});

it('merges local and remote users of a scaled presence channel', function () {
    scaleChannelUsersFleet([['id' => 2], ['id' => 9]]);

    $channel = channels()->findOrCreate('presence-test-channel');

    foreach ([1, 2] as $id) {
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
        ->and(json_decode(RequestSigner::body($response), true)['users'])
        ->toEqualCanonicalizing([['id' => 1], ['id' => 2], ['id' => 9]]);
});

it('returns 404 when a scaled presence channel is unoccupied on every node', function () {
    $bus = scaleChannelUsersFleet([]);

    $response = (new ChannelUsersController)->handleRequest(signedChannelRequest(
        'GET',
        '/apps/app-id/channels/presence-test-channel/users',
        routeParams: ['appId' => 'app-id', 'channel' => 'presence-test-channel'],
    ));

    expect($response->getStatus())->toBe(404)
        ->and($bus->requests())->toHaveCount(1);
});

it('returns 400 for a non-presence channel under scaling without gathering', function () {
    $bus = scaleChannelUsersFleet([['id' => 7]]);

    $response = (new ChannelUsersController)->handleRequest(signedChannelRequest(
        'GET',
        '/apps/app-id/channels/test-channel/users',
        routeParams: ['appId' => 'app-id', 'channel' => 'test-channel'],
    ));

    expect($response->getStatus())->toBe(400)
        ->and($bus->requests())->toBe([]);
});

it('fails when using an invalid signature', function () {
    $request = new Request(
        Mockery::mock(Client::class),
        'GET',
        Http::new('http://localhost/apps/app-id/channels/presence-test-channel/users?auth_signature=deadbeef'),
    );
    $request->setAttribute(Router::class, ['appId' => 'app-id', 'channel' => 'presence-test-channel']);

    $response = (new ChannelUsersController)->handleRequest($request);

    expect($response->getStatus())->toBe(401);
});

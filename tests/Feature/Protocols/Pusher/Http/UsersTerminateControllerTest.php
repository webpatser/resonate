<?php

use Fledge\Async\Http\Server\Driver\Client;
use Fledge\Async\Http\Server\Request as FledgeRequest;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\UsersTerminateController;
use Webpatser\Resonate\Server\Router;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

/*
 * Exercises the user-termination HTTP controller: POST
 * /apps/{appId}/users/{userId}/terminate_connections disconnects every local
 * connection whose presence `user_id` matches the route parameter and leaves
 * the rest untouched. The cross-node pub/sub `terminate` fan-out is Phase 4.
 */

/**
 * Build a fledge request signed the way Pusher signs it.
 *
 * Mirrors the helper in tests/Unit/Protocols/Pusher/Http/ControllerTest.php;
 * the Unit one is not in scope from the Feature suite.
 *
 * @param  array<string, string>  $query
 * @param  array<string, string>  $routeParams
 */
function signedTerminateRequest(string $path, array $query, array $routeParams): FledgeRequest
{
    $params = $query;
    ksort($params);

    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = "{$key}={$value}";
    }

    $query['auth_signature'] = hash_hmac(
        'sha256',
        implode("\n", ['POST', $path, implode('&', $pairs)]),
        'app-secret',
    );

    $request = new FledgeRequest(
        Mockery::mock(Client::class),
        'POST',
        League\Uri\Http::new('http://localhost'.$path.'?'.http_build_query($query)),
        [],
        '',
    );

    $request->setAttribute(Router::class, $routeParams);

    return $request;
}

/**
 * Subscribe a fresh fake connection to a presence channel with the given data.
 */
function seedConnection(string $channel, array $data): FakeConnection
{
    $connection = new FakeConnection;
    $payload = json_encode($data);

    channels()->findOrCreate($channel)->subscribe(
        $connection,
        validAuth($connection->id(), $channel, $payload),
        $payload,
    );

    return $connection;
}

it('terminates every local connection matching the given user', function () {
    $target = seedConnection('presence-test-channel-one', ['user_id' => '456']);
    channels()->findOrCreate('test-channel-two')->subscribe($target);

    $other = seedConnection('presence-test-channel-one', ['user_id' => '123']);

    $response = (new UsersTerminateController)->handleRequest(signedTerminateRequest(
        '/apps/app-id/users/456/terminate_connections',
        ['auth_key' => 'app-key', 'auth_timestamp' => (string) time(), 'auth_version' => '1.0'],
        ['appId' => 'app-id', 'userId' => '456'],
    ));

    expect($response->getStatus())->toBe(200)
        ->and($target->wasTerminated)->toBeTrue()
        ->and($other->wasTerminated)->toBeFalse();
});

it('terminates nothing when no connection matches the user', function () {
    $one = seedConnection('presence-test-channel-one', ['user_id' => '123']);
    $two = seedConnection('presence-test-channel-one', ['user_id' => '456']);

    $response = (new UsersTerminateController)->handleRequest(signedTerminateRequest(
        '/apps/app-id/users/not-a-user/terminate_connections',
        ['auth_key' => 'app-key', 'auth_timestamp' => (string) time(), 'auth_version' => '1.0'],
        ['appId' => 'app-id', 'userId' => 'not-a-user'],
    ));

    expect($response->getStatus())->toBe(200)
        ->and($one->wasTerminated)->toBeFalse()
        ->and($two->wasTerminated)->toBeFalse();
});

it('returns 404 for an unknown application id', function () {
    $response = (new UsersTerminateController)->handleRequest(signedTerminateRequest(
        '/apps/missing/users/456/terminate_connections',
        ['auth_key' => 'app-key'],
        ['appId' => 'missing', 'userId' => '456'],
    ));

    expect($response->getStatus())->toBe(404);
});

it('returns 401 for an invalid signature', function () {
    $tampered = new FledgeRequest(
        Mockery::mock(Client::class),
        'POST',
        League\Uri\Http::new('http://localhost/apps/app-id/users/456/terminate_connections?auth_key=app-key&auth_signature=deadbeef'),
    );
    $tampered->setAttribute(Router::class, ['appId' => 'app-id', 'userId' => '456']);

    expect((new UsersTerminateController)->handleRequest($tampered)->getStatus())->toBe(401);
});

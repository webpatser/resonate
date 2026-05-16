<?php

use Fledge\Async\Http\Server\Driver\Client;
use Fledge\Async\Http\Server\Request as FledgeRequest;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\Controller;
use Webpatser\Resonate\Server\Request;
use Webpatser\Resonate\Server\Response;
use Webpatser\Resonate\Server\Router;

/*
 * Exercises the base HTTP controller: the verbatim-ported Pusher signature
 * canonicalization and the verify() error branches (missing/unknown app,
 * bad signature). The genuine cross-check against pusher/pusher-php-server's
 * own signer lives in the 3B/3C integration tests.
 */

/**
 * A concrete controller that records the verified request and returns 200.
 */
function testController(): Controller
{
    return new class extends Controller
    {
        public ?Request $handled = null;

        protected function handle(Request $request, array $parameters): Response
        {
            $this->handled = $request;

            return Response::json(['ok' => true]);
        }
    };
}

/**
 * Build a fledge request signed the way Pusher signs it.
 *
 * @param  array<string, string>  $query  Query params, excluding auth_signature.
 */
function signedRequest(string $method, string $path, array $query = [], string $body = '', array $routeParams = ['appId' => 'app-id']): FledgeRequest
{
    $params = $query;

    if ($body !== '') {
        $params['body_md5'] = md5($body);
    }

    ksort($params);

    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = "{$key}={$value}";
    }

    $query['auth_signature'] = hash_hmac(
        'sha256',
        implode("\n", [$method, $path, implode('&', $pairs)]),
        'app-secret',
    );

    $uri = 'http://localhost'.$path.'?'.http_build_query($query);

    $request = new FledgeRequest(
        Mockery::mock(Client::class),
        $method,
        League\Uri\Http::new($uri),
        [],
        $body,
    );

    $request->setAttribute(Router::class, $routeParams);

    return $request;
}

it('passes a request with a valid signature through to handle()', function () {
    $controller = testController();

    $response = $controller->handleRequest(signedRequest('GET', '/apps/app-id/channels', [
        'auth_key' => 'app-key',
        'auth_timestamp' => '1700000000',
        'auth_version' => '1.0',
    ]));

    expect($response->getStatus())->toBe(200)
        ->and($controller->handled)->toBeInstanceOf(Request::class);
});

it('signs a non-empty body with body_md5', function () {
    $controller = testController();
    $body = json_encode(['name' => 'OrderShipped', 'channel' => 'orders', 'data' => '{}']);

    $response = $controller->handleRequest(signedRequest('POST', '/apps/app-id/events', [
        'auth_key' => 'app-key',
        'auth_timestamp' => '1700000000',
        'auth_version' => '1.0',
    ], $body));

    expect($response->getStatus())->toBe(200);
});

it('rejects a request with an invalid signature with 401', function () {
    $request = signedRequest('GET', '/apps/app-id/channels', [
        'auth_key' => 'app-key',
        'auth_timestamp' => '1700000000',
        'auth_version' => '1.0',
    ]);

    // Tamper with the signature after it was computed.
    $tampered = new FledgeRequest(
        Mockery::mock(Client::class),
        'GET',
        League\Uri\Http::new('http://localhost/apps/app-id/channels?auth_key=app-key&auth_signature=deadbeef'),
    );
    $tampered->setAttribute(Router::class, ['appId' => 'app-id']);

    $response = testController()->handleRequest($tampered);

    expect($response->getStatus())->toBe(401);
});

it('returns 400 when no application id is present', function () {
    $request = signedRequest('GET', '/apps//channels', [
        'auth_key' => 'app-key',
    ], routeParams: []);

    $response = testController()->handleRequest($request);

    expect($response->getStatus())->toBe(400);
});

it('returns 404 for an unknown application id', function () {
    $request = signedRequest('GET', '/apps/missing/channels', [
        'auth_key' => 'app-key',
    ], routeParams: ['appId' => 'missing']);

    $response = testController()->handleRequest($request);

    expect($response->getStatus())->toBe(404);
});

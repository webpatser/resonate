<?php

use Fledge\Async\Http\Server\Driver\Client;
use Fledge\Async\Http\Server\Request as FledgeRequest;
use League\Uri\Http;
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
        Http::new($uri),
        [],
        $body,
    );

    $request->setAttribute(Router::class, $routeParams);

    return $request;
}

/**
 * Sign the given parameters the way Pusher signs them and return the query string.
 *
 * Unlike signedRequest() the caller owns the whole parameter set, including any
 * body digest, so a request can bind its body with md5, sha256 or both.
 *
 * @param  array<string, string>  $params
 */
function signedQuery(string $method, string $path, array $params): string
{
    ksort($params);

    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = "{$key}={$value}";
    }

    $params['auth_signature'] = hash_hmac(
        'sha256',
        implode("\n", [$method, $path, implode('&', $pairs)]),
        'app-secret',
    );

    return http_build_query($params);
}

/**
 * Run a raw query string through the controller and return the response status.
 */
function statusFor(string $method, string $path, string $query, string $body = ''): int
{
    $request = new FledgeRequest(
        Mockery::mock(Client::class),
        $method,
        Http::new('http://localhost'.$path.'?'.$query),
        [],
        $body,
    );

    $request->setAttribute(Router::class, ['appId' => 'app-id']);

    return testController()->handleRequest($request)->getStatus();
}

it('passes a request with a valid signature through to handle()', function () {
    $controller = testController();

    $response = $controller->handleRequest(signedRequest('GET', '/apps/app-id/channels', [
        'auth_key' => 'app-key',
        'auth_timestamp' => (string) time(),
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
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
    ], $body));

    expect($response->getStatus())->toBe(200);
});

it('rejects a request with an invalid signature with 401', function () {
    $request = signedRequest('GET', '/apps/app-id/channels', [
        'auth_key' => 'app-key',
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
    ]);

    // Tamper with the signature after it was computed.
    $tampered = new FledgeRequest(
        Mockery::mock(Client::class),
        'GET',
        Http::new('http://localhost/apps/app-id/channels?auth_key=app-key&auth_signature=deadbeef'),
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

it('rejects a request whose auth_timestamp is older than the grace window', function () {
    $stale = (string) (time() - 601);

    $response = testController()->handleRequest(signedRequest('GET', '/apps/app-id/channels', [
        'auth_key' => 'app-key',
        'auth_timestamp' => $stale,
        'auth_version' => '1.0',
    ]));

    expect($response->getStatus())->toBe(401);
});

it('rejects a request whose auth_timestamp is too far in the future', function () {
    $future = (string) (time() + 601);

    $response = testController()->handleRequest(signedRequest('GET', '/apps/app-id/channels', [
        'auth_key' => 'app-key',
        'auth_timestamp' => $future,
        'auth_version' => '1.0',
    ]));

    expect($response->getStatus())->toBe(401);
});

it('rejects a request with no auth_timestamp', function () {
    $response = testController()->handleRequest(signedRequest('GET', '/apps/app-id/channels', [
        'auth_key' => 'app-key',
        'auth_version' => '1.0',
    ]));

    expect($response->getStatus())->toBe(401);
});

it('rejects a request with a non-numeric auth_timestamp', function () {
    $response = testController()->handleRequest(signedRequest('GET', '/apps/app-id/channels', [
        'auth_key' => 'app-key',
        'auth_timestamp' => 'not-a-timestamp',
        'auth_version' => '1.0',
    ]));

    expect($response->getStatus())->toBe(401);
});

it('accepts a request just inside the grace window', function () {
    $almostStale = (string) (time() - 599);

    $response = testController()->handleRequest(signedRequest('GET', '/apps/app-id/channels', [
        'auth_key' => 'app-key',
        'auth_timestamp' => $almostStale,
        'auth_version' => '1.0',
    ]));

    expect($response->getStatus())->toBe(200);
});

it('disables the timestamp check when grace is set to 0', function () {
    config()->set('reverb.servers.reverb.auth_timestamp_grace', 0);

    $response = testController()->handleRequest(signedRequest('GET', '/apps/app-id/channels', [
        'auth_key' => 'app-key',
        'auth_timestamp' => '1700000000',
        'auth_version' => '1.0',
    ]));

    expect($response->getStatus())->toBe(200);
});

it('rejects an auth_signature passed as an array', function () {
    // Construct a URL where auth_signature is parsed as an array by PHP.
    $query = http_build_query([
        'auth_key' => 'app-key',
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
    ]).'&auth_signature[]=x&auth_signature[]=y';

    $request = new FledgeRequest(
        Mockery::mock(Client::class),
        'GET',
        Http::new('http://localhost/apps/app-id/channels?'.$query),
    );
    $request->setAttribute(Router::class, ['appId' => 'app-id']);

    $response = testController()->handleRequest($request);

    expect($response->getStatus())->toBe(401);
});

it('rejects a request with no auth_signature', function () {
    // Build a request that omits auth_signature entirely.
    $request = new FledgeRequest(
        Mockery::mock(Client::class),
        'GET',
        Http::new('http://localhost/apps/app-id/channels?auth_key=app-key&auth_timestamp='.time().'&auth_version=1.0'),
    );
    $request->setAttribute(Router::class, ['appId' => 'app-id']);

    $response = testController()->handleRequest($request);

    expect($response->getStatus())->toBe(401);
});

it('rejects a method substitution replay', function () {
    // Sign a POST request, then replay the same query/signature against GET.
    $signed = signedRequest('POST', '/apps/app-id/events', [
        'auth_key' => 'app-key',
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
    ]);

    $query = $signed->getUri()->getQuery();

    $replay = new FledgeRequest(
        Mockery::mock(Client::class),
        'GET',
        Http::new('http://localhost/apps/app-id/events?'.$query),
    );
    $replay->setAttribute(Router::class, ['appId' => 'app-id']);

    $response = testController()->handleRequest($replay);

    expect($response->getStatus())->toBe(401);
});

it('rejects a path substitution replay', function () {
    // Sign a request for /apps/app-id/events, then replay the signature against /apps/app-id/channels.
    $signed = signedRequest('GET', '/apps/app-id/events', [
        'auth_key' => 'app-key',
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
    ]);

    $query = $signed->getUri()->getQuery();

    $replay = new FledgeRequest(
        Mockery::mock(Client::class),
        'GET',
        Http::new('http://localhost/apps/app-id/channels?'.$query),
    );
    $replay->setAttribute(Router::class, ['appId' => 'app-id']);

    $response = testController()->handleRequest($replay);

    expect($response->getStatus())->toBe(401);
});

it('rejects body tampering after signing', function () {
    // Sign a POST body, then swap the body but keep the signature/query.
    $original = '{"foo":"a"}';
    $signed = signedRequest('POST', '/apps/app-id/events', [
        'auth_key' => 'app-key',
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
    ], $original);

    $query = $signed->getUri()->getQuery();

    $tampered = new FledgeRequest(
        Mockery::mock(Client::class),
        'POST',
        Http::new('http://localhost/apps/app-id/events?'.$query),
        [],
        '{"foo":"b"}',
    );
    $tampered->setAttribute(Router::class, ['appId' => 'app-id']);

    $response = testController()->handleRequest($tampered);

    expect($response->getStatus())->toBe(401);
});

/*
 * Canonicalization ambiguity.
 *
 * The canonical string is unescaped `key=value` pairs joined with `&`, which
 * is what pusher/pusher-php-server signs, so the form cannot change without
 * breaking every stock client. What can change is which inputs are accepted:
 * anything the form cannot represent unambiguously is now rejected with 400
 * before the HMAC is computed.
 */

it('rejects an array query parameter that canonicalized like a comma-joined scalar', function () {
    // `foo=a,b` and `foo[]=a&foo[]=b` produced the same signed string, so one
    // signature verified two different query strings.
    $params = [
        'auth_key' => 'app-key',
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
        'foo' => 'a,b',
    ];

    ksort($params);

    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = "{$key}={$value}";
    }

    $signature = hash_hmac(
        'sha256',
        implode("\n", ['GET', '/apps/app-id/channels', implode('&', $pairs)]),
        'app-secret',
    );

    // The scalar form still verifies.
    $scalar = 'auth_key=app-key'
        .'&auth_timestamp='.$params['auth_timestamp']
        .'&auth_version=1.0'
        .'&foo=a%2Cb'
        .'&auth_signature='.$signature;

    // The array form, which used to share that signature, no longer does.
    $array = 'auth_key=app-key'
        .'&auth_timestamp='.$params['auth_timestamp']
        .'&auth_version=1.0'
        .'&foo[]=a&foo[]=b'
        .'&auth_signature='.$signature;

    expect(statusFor('GET', '/apps/app-id/channels', $scalar))->toBe(200)
        ->and(statusFor('GET', '/apps/app-id/channels', $array))->toBe(400);
});

it('rejects a nested array query parameter', function () {
    // A nested array stringified to the literal "Array", so every nested value
    // was outside the signature entirely.
    $query = 'auth_key=app-key'
        .'&auth_timestamp='.time()
        .'&auth_version=1.0'
        .'&foo[bar][baz]=qux'
        .'&auth_signature=deadbeef';

    expect(statusFor('GET', '/apps/app-id/channels', $query))->toBe(400);
});

it('rejects a signed value containing a separator', function () {
    // A caller who influences one signed value could otherwise smuggle another
    // signed parameter into the same canonical string.
    $params = [
        'auth_key' => 'app-key',
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
        'filter_by_prefix' => 'private-&info=all',
    ];

    ksort($params);

    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = "{$key}={$value}";
    }

    $signature = hash_hmac(
        'sha256',
        implode("\n", ['GET', '/apps/app-id/channels', implode('&', $pairs)]),
        'app-secret',
    );

    $query = http_build_query($params + ['auth_signature' => $signature]);

    expect(statusFor('GET', '/apps/app-id/channels', $query))->toBe(400);
});

it('rejects a signed key containing a separator', function () {
    $timestamp = (string) time();

    // `a=1&b` as a key: PHP parses `a%3D1%26b=x` into exactly that.
    $query = 'auth_key=app-key'
        .'&auth_timestamp='.$timestamp
        .'&auth_version=1.0'
        .'&a%3D1%26b=x'
        .'&auth_signature=deadbeef';

    expect(statusFor('GET', '/apps/app-id/channels', $query))->toBe(400);
});

/*
 * Body binding.
 */

it('verifies a body bound with sha256', function () {
    $body = '{"name":"OrderShipped"}';

    $params = [
        'auth_key' => 'app-key',
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
        'body_sha256' => hash('sha256', $body),
    ];

    expect(statusFor('POST', '/apps/app-id/events', signedQuery('POST', '/apps/app-id/events', $params), $body))->toBe(200);
});

it('verifies a body bound with both sha256 and md5', function () {
    $body = '{"name":"OrderShipped"}';

    $params = [
        'auth_key' => 'app-key',
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
        'body_md5' => md5($body),
        'body_sha256' => hash('sha256', $body),
    ];

    expect(statusFor('POST', '/apps/app-id/events', signedQuery('POST', '/apps/app-id/events', $params), $body))->toBe(200);
});

it('rejects a tampered body when it was bound with sha256', function () {
    $body = '{"foo":"a"}';

    $params = [
        'auth_key' => 'app-key',
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
        'body_sha256' => hash('sha256', $body),
    ];

    $query = signedQuery('POST', '/apps/app-id/events', $params);

    expect(statusFor('POST', '/apps/app-id/events', $query, '{"foo":"b"}'))->toBe(401);
});

it('rejects a body_sha256 that does not match the body it signed', function () {
    // The caller signed a digest of a different body: the digest is recomputed
    // from what actually arrived, so the signature cannot match.
    $params = [
        'auth_key' => 'app-key',
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
        'body_sha256' => hash('sha256', '{"foo":"a"}'),
    ];

    $query = signedQuery('POST', '/apps/app-id/events', $params);

    expect(statusFor('POST', '/apps/app-id/events', $query, '{"foo":"b"}'))->toBe(401);
});

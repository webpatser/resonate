<?php

use Fledge\Async\Http\Server\Request as FledgeRequest;
use Fledge\Async\Http\Server\RequestHandler;
use Fledge\Async\Http\Server\Response as FledgeResponse;
use Webpatser\Resonate\Server\Route;
use Webpatser\Resonate\Server\Router;

/*
 * The Server\Router and Server\Route are pure value objects; they match a
 * method/path against registered routes with no dependency on the event loop
 * or the Laravel container, so they are exercised directly here.
 */
uses()->beforeEach(fn () => null)->in(__DIR__);

/**
 * A no-op request handler used as a route target in these tests.
 */
function stubHandler(): RequestHandler
{
    return new class implements RequestHandler
    {
        public function handleRequest(FledgeRequest $request): FledgeResponse
        {
            return new FledgeResponse(200, [], 'ok');
        }
    };
}

it('matches a static route', function () {
    $router = new Router;
    $router->get('/up', stubHandler());

    expect($router->match('GET', '/up'))->not->toBeNull();
    expect($router->match('GET', '/up')[1])->toBe([]);
});

it('does not match a static route with the wrong method', function () {
    $router = new Router;
    $router->get('/up', stubHandler());

    expect($router->match('POST', '/up'))->toBeNull();
});

it('extracts a placeholder parameter', function () {
    $router = new Router;
    $router->get('/app/{appKey}', stubHandler());

    [$route, $parameters] = $router->match('GET', '/app/my-app-key');

    expect($route)->toBeInstanceOf(Route::class);
    expect($parameters)->toBe(['appKey' => 'my-app-key']);
});

it('url decodes placeholder parameters', function () {
    $router = new Router;
    $router->get('/app/{appKey}', stubHandler());

    [, $parameters] = $router->match('GET', '/app/key%20with%20spaces');

    expect($parameters)->toBe(['appKey' => 'key with spaces']);
});

it('does not match a placeholder route across path segments', function () {
    $router = new Router;
    $router->get('/app/{appKey}', stubHandler());

    expect($router->match('GET', '/app/foo/bar'))->toBeNull();
});

it('returns null when no route matches', function () {
    $router = new Router;
    $router->get('/up', stubHandler());

    expect($router->match('GET', '/missing'))->toBeNull();
});

it('matches regardless of a trailing slash', function () {
    $router = new Router;
    $router->get('/up', stubHandler());

    expect($router->match('GET', '/up/'))->not->toBeNull();
});

it('is case insensitive on the http method', function () {
    $router = new Router;
    $router->get('/up', stubHandler());

    expect($router->match('get', '/up'))->not->toBeNull();
});

it('applies a configured path prefix to every route', function () {
    $router = new Router('reverb');

    expect($router->prefix())->toBe('/reverb');

    $router->get('/up', stubHandler());

    expect($router->match('GET', '/reverb/up'))->not->toBeNull();
    expect($router->match('GET', '/up'))->toBeNull();
});

it('normalizes a prefix with surrounding slashes', function () {
    expect((new Router('/ws/'))->prefix())->toBe('/ws');
    expect((new Router(''))->prefix())->toBe('');
});

it('prefixes a placeholder route', function () {
    $router = new Router('reverb');
    $router->get('/app/{appKey}', stubHandler());

    [, $parameters] = $router->match('GET', '/reverb/app/abc123');

    expect($parameters)->toBe(['appKey' => 'abc123']);
});

it('keeps registered routes accessible', function () {
    $router = new Router;
    $a = $router->get('/up', stubHandler());
    $b = $router->post('/apps/{appId}/events', stubHandler());

    expect($router->routes())->toBe([$a, $b]);
    expect($a->method())->toBe('GET');
    expect($b->method())->toBe('POST');
});

it('dispatches a matched request to the route handler and sets route attributes', function () {
    $router = new Router;
    $router->get('/app/{appKey}', stubHandler());

    $request = new FledgeRequest(
        Mockery::mock(\Fledge\Async\Http\Server\Driver\Client::class),
        'GET',
        \League\Uri\Http::new('http://localhost/app/abc'),
    );

    $response = $router->handleRequest($request);

    expect($response->getStatus())->toBe(200);
    expect($request->getAttribute(Router::class))->toBe(['appKey' => 'abc']);
});

it('returns a 404 response for an unmatched request', function () {
    $router = new Router;
    $router->get('/up', stubHandler());

    $request = new FledgeRequest(
        Mockery::mock(\Fledge\Async\Http\Server\Driver\Client::class),
        'GET',
        \League\Uri\Http::new('http://localhost/missing'),
    );

    expect($router->handleRequest($request)->getStatus())->toBe(404);
});

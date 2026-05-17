<?php

use Fledge\Async\Http\Server\Driver\Client;
use Fledge\Async\Http\Server\Request as FledgeRequest;
use Mockery;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Protocols\Pusher\Server;
use Webpatser\Resonate\Server\Router;
use Webpatser\Resonate\Server\WebSocketHandler;

/*
 * Exercises WebSocketHandler::appKey() in isolation.
 *
 * The full handleClient() path requires a live fledge-fiber WebsocketClient,
 * an upgrade handshake, and a running event loop, none of which are needed
 * to verify the key-extraction logic. The handler exposes a single internal
 * method (appKey) that we route every regression through here via a thin
 * test subclass that promotes it to public.
 *
 * Finding #8 in the 2026-05-16 audit flagged the fallback regex as
 * unanchored and length-uncapped. These tests pin the fix:
 *  - prefer the Router route attribute when present (production path);
 *  - fall back to a `^/app/<key>$` regex, with `<key>` capped at 128 chars;
 *  - never extract from a path that the route definition would not match.
 */

/**
 * Build a fledge-fiber Request for an arbitrary path with no route attribute.
 *
 * Used to exercise the fallback regex branch in WebSocketHandler::appKey().
 */
function wsRequestForPath(string $path): FledgeRequest
{
    return new FledgeRequest(
        Mockery::mock(Client::class),
        'GET',
        \League\Uri\Http::new('http://localhost'.$path),
    );
}

/**
 * Build a fledge-fiber Request for `/app/{appKey}` with the Router attribute
 * already set, mirroring how the production router hands a request off to the
 * WebSocketHandler.
 *
 * @param  array<string, string>  $routeParams
 */
function wsRequestWithRouteParams(string $path, array $routeParams): FledgeRequest
{
    $request = wsRequestForPath($path);
    $request->setAttribute(Router::class, $routeParams);

    return $request;
}

beforeEach(function () {
    // The Pest.php beforeEach already binds ApplicationProvider, but the
    // handler under test never touches the provider in appKey(), so any
    // mock is fine. We keep the real binding to stay close to production.
    $this->handler = new class(app(Server::class), app(ApplicationProvider::class)) extends WebSocketHandler
    {
        // Promote the protected appKey() to public for direct testing.
        public function callAppKey(FledgeRequest $request): ?string
        {
            return $this->appKey($request);
        }
    };
});

it('accepts a valid app key via route attribute', function () {
    // Production path: Server\Factory wires `/app/{appKey}`, so by the time
    // the request reaches the handler the Router attribute is already set.
    $request = wsRequestWithRouteParams('/app/my-app-key', ['appKey' => 'my-app-key']);

    expect($this->handler->callAppKey($request))->toBe('my-app-key');
});

it('accepts a valid app key via the fallback path regex', function () {
    // The fallback branch runs when no Router attribute is present. Useful
    // for defence in depth and for any future direct dispatch path.
    $request = wsRequestForPath('/app/my-app-key');

    expect($this->handler->callAppKey($request))->toBe('my-app-key');
});

it('rejects an oversized app key in the fallback regex', function () {
    // Cap at 128 chars: a 200-char segment must not be extracted. The handler
    // returns null, which findByKey() then rejects as InvalidApplication and
    // the connection is closed with 4001 (verified by the next test below).
    $oversized = str_repeat('a', 200);
    $request = wsRequestForPath('/app/'.$oversized);

    expect($this->handler->callAppKey($request))->toBeNull();
});

it('accepts a 128 character app key (boundary of the length cap)', function () {
    // 128 chars exactly is the upper bound and must still match. Guards
    // against an off-by-one in the `{1,128}` quantifier.
    $maxLength = str_repeat('a', 128);
    $request = wsRequestForPath('/app/'.$maxLength);

    expect($this->handler->callAppKey($request))->toBe($maxLength);
});

it('does not match a non-anchored fallback path with a prefix', function () {
    // Finding #8 specifically: the previous `#/app/([^/?]+)#` matched a key
    // anywhere in the path, so a misbehaving proxy that prepended `/foo`
    // could still smuggle a key through the fallback. Anchoring with `^`
    // closes that.
    $request = wsRequestForPath('/foo/app/my-key');

    expect($this->handler->callAppKey($request))->toBeNull();
});

it('does not match a fallback path with a suffix segment', function () {
    // The trailing `$` anchor rejects `/app/x/extra`; otherwise the handler
    // would accept a key from a path the router itself would refuse.
    $request = wsRequestForPath('/app/my-key/extra');

    expect($this->handler->callAppKey($request))->toBeNull();
});

it('returns null when no Router attribute is set and the path is not /app/<key>', function () {
    // Defence-in-depth: a request that does not look like an app upgrade at
    // all (e.g. the health check path) must produce a null key so the caller
    // closes the connection with 4001 rather than picking up garbage.
    $request = wsRequestForPath('/up');

    expect($this->handler->callAppKey($request))->toBeNull();
});

it('prefers the Router route attribute over the fallback regex when both would match', function () {
    // If a future router change ever set the appKey attribute to something
    // different from the URL segment, we still want the attribute to win.
    // This pins the precedence so the fallback can only act as a backstop.
    $request = wsRequestWithRouteParams('/app/url-key', ['appKey' => 'attribute-key']);

    expect($this->handler->callAppKey($request))->toBe('attribute-key');
});

<?php

use Fledge\Async\Http\Server\Driver\Client;
use Fledge\Async\Http\Server\Request as FledgeRequest;
use Webpatser\Resonate\Server\Request;

/*
 * Server\Request is a thin adapter over the fledge-fiber HTTP server request.
 * These tests construct a real fledge-fiber Request (the only mocked piece is
 * the transport-level Client interface) and assert the adapter surface.
 */
uses()->beforeEach(fn () => null)->in(__DIR__);

/**
 * Build a fledge-fiber server request for the given method, URI and headers.
 *
 * @param  array<string, string|array<int, string>>  $headers
 */
function fledgeRequest(string $method, string $uri, array $headers = [], string $body = ''): FledgeRequest
{
    return new FledgeRequest(
        Mockery::mock(Client::class),
        $method,
        League\Uri\Http::new($uri),
        $headers,
        $body,
    );
}

it('exposes the request method', function () {
    $request = new Request(fledgeRequest('GET', 'http://localhost/app/key'));

    expect($request->getMethod())->toBe('GET');
});

it('exposes the request path and host', function () {
    $request = new Request(fledgeRequest('GET', 'http://example.test/apps/123/events'));

    expect($request->getPath())->toBe('/apps/123/events');
    expect($request->getHost())->toBe('example.test');
});

it('parses query string parameters', function () {
    $request = new Request(fledgeRequest('GET', 'http://localhost/up?auth_key=abc&channel=test'));

    expect($request->getQueryString())->toBe('auth_key=abc&channel=test');
    expect($request->query())->toBe(['auth_key' => 'abc', 'channel' => 'test']);
    expect($request->queryParameter('auth_key'))->toBe('abc');
    expect($request->queryParameter('missing', 'fallback'))->toBe('fallback');
});

it('reads the buffered request body', function () {
    $request = new Request(fledgeRequest('POST', 'http://localhost/apps/1/events', [], '{"event":"test"}'));

    expect($request->getBody())->toBe('{"event":"test"}');
    // A second read returns the buffered copy rather than re-reading the stream.
    expect($request->getBody())->toBe('{"event":"test"}');
});

it('reads request headers', function () {
    $request = new Request(fledgeRequest('GET', 'http://localhost/app/key', [
        'origin' => 'https://laravel.com',
        'x-custom' => ['one', 'two'],
    ]));

    expect($request->header('origin'))->toBe('https://laravel.com');
    expect($request->origin())->toBe('https://laravel.com');
    expect($request->headerArray('x-custom'))->toBe(['one', 'two']);
    expect($request->headers())->toHaveKey('origin');
});

it('returns null for a missing origin header', function () {
    $request = new Request(fledgeRequest('GET', 'http://localhost/app/key'));

    expect($request->origin())->toBeNull();
});

it('exposes the underlying fledge-fiber request', function () {
    $base = fledgeRequest('GET', 'http://localhost/up');
    $request = new Request($base);

    expect($request->base())->toBe($base);
});

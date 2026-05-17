<?php

use Webpatser\Resonate\Protocols\Pusher\Server;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

/*
 * Exercises Server::verifyOrigin(). The audit found zero coverage for this
 * path; these cases pin the documented SECURITY.md behaviour: `*` short-circuit,
 * exact-match (not subdomains), `*.example.com` does not match the apex, and
 * case-insensitive matching (the fix). Origin verification is enforced at
 * connection-open time; an InvalidOrigin exception lands as a 4009 pusher:error
 * frame on the connection.
 */

beforeEach(function () {
    $this->server = $this->app->make(Server::class);
});

function assertOriginAllowed(FakeConnection $connection): void
{
    expect(collect($connection->messages)->contains(
        fn ($m) => str_contains($m, '"event":"pusher:error"') && str_contains($m, 'origin')
    ))->toBeFalse();
    $connection->assertReceived([
        'event' => 'pusher:connection_established',
        'data' => json_encode([
            'socket_id' => $connection->id(),
            'activity_timeout' => 30,
        ]),
    ]);
}

function assertOriginRejected(FakeConnection $connection): void
{
    $connection->assertReceived([
        'event' => 'pusher:error',
        'data' => json_encode([
            'code' => 4009,
            'message' => 'Origin not allowed',
        ]),
    ]);
}

it('short-circuits when * is in the allow-list', function () {
    $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['*']);

    $this->server->open($connection = new FakeConnection(origin: 'http://anything.example.org:1234'));

    assertOriginAllowed($connection);
});

it('allows an exact match on the configured host', function () {
    $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['example.com']);

    $this->server->open($connection = new FakeConnection(origin: 'https://example.com'));

    assertOriginAllowed($connection);
});

it('rejects a subdomain when the pattern is the bare apex', function () {
    $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['example.com']);

    $this->server->open($connection = new FakeConnection(origin: 'https://sub.example.com'));

    assertOriginRejected($connection);
});

it('matches subdomains with the *.example.com pattern', function () {
    $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['*.example.com']);

    $this->server->open($connection = new FakeConnection(origin: 'https://sub.example.com'));

    assertOriginAllowed($connection);
});

it('does NOT match the apex with the *.example.com pattern', function () {
    // Documented Pusher fnmatch quirk: `*.example.com` requires at least one
    // subdomain label. To allow both, configure ['example.com', '*.example.com'].
    $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['*.example.com']);

    $this->server->open($connection = new FakeConnection(origin: 'https://example.com'));

    assertOriginRejected($connection);
});

it('rejects a missing Origin header when * is not in the allow-list', function () {
    $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['example.com']);

    $this->server->open($connection = new FakeConnection(origin: null));

    assertOriginRejected($connection);
});

it('rejects an empty Origin header when * is not in the allow-list', function () {
    $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['example.com']);

    $this->server->open($connection = new FakeConnection(origin: ''));

    assertOriginRejected($connection);
});

it('matches case-insensitively against the configured pattern', function () {
    $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['example.com']);

    $this->server->open($connection = new FakeConnection(origin: 'https://EXAMPLE.com'));

    assertOriginAllowed($connection);
});

it('matches case-insensitively when the pattern is mixed case too', function () {
    $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['Example.COM']);

    $this->server->open($connection = new FakeConnection(origin: 'https://example.com'));

    assertOriginAllowed($connection);
});

it('strips the port from the Origin host before matching', function () {
    $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['example.com']);

    $this->server->open($connection = new FakeConnection(origin: 'https://example.com:8443'));

    assertOriginAllowed($connection);
});

it('rejects a literal Origin: null string', function () {
    $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['example.com']);

    // Browsers send `Origin: null` for sandboxed iframes and file:// pages.
    $this->server->open($connection = new FakeConnection(origin: 'null'));

    assertOriginRejected($connection);
});

it('accepts a punycode origin against the punycode pattern', function () {
    // Resonate does not perform IDN normalization (per SECURITY.md). Operators
    // must configure the punycode form if their domain has non-ASCII characters.
    $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['xn--exmple-cua.com']);

    $this->server->open($connection = new FakeConnection(origin: 'https://xn--exmple-cua.com'));

    assertOriginAllowed($connection);
});

it('does NOT match a non-normalized unicode origin against a punycode pattern', function () {
    $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['xn--exmple-cua.com']);

    // `exämple.com` does not get punycoded by the server — operators must send the punycode form.
    $this->server->open($connection = new FakeConnection(origin: 'https://exämple.com'));

    assertOriginRejected($connection);
});

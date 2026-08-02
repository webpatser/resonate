<?php

use Webpatser\Resonate\Application;
use Webpatser\Resonate\Exceptions\InvalidConfiguration;
use Webpatser\Resonate\Protocols\Pusher\Server;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

/*
 * Exercises Server::ensureWithinRateLimit().
 *
 * The limiter used to key on the socket id alone, which is fresh random per
 * connection: a client that tripped the limit reset its quota by reconnecting,
 * which `terminate_on_limit` actively invites. Nothing cleared the keys either,
 * so every connection left two array-store entries behind for the lifetime of
 * the process. It now counts two dimensions: the client (application plus
 * remote address), which survives a reconnect, and the connection, which is
 * released on close.
 */

beforeEach(function () {
    $this->server = $this->app->make(Server::class);

    $this->app['config']->set('reverb.apps.apps.0.rate_limiting', [
        'enabled' => true,
        'max_attempts' => 2,
        'decay_seconds' => 60,
        'terminate_on_limit' => false,
    ]);
});

/**
 * Send a subscribe message on the given connection.
 */
function sendMessage(FakeConnection $connection, string $channel = 'public-one'): void
{
    test()->server->message($connection, json_encode([
        'event' => 'pusher:subscribe',
        'data' => ['channel' => $channel],
    ]));
}

/**
 * Determine whether the connection was told it hit the rate limit.
 */
function wasRateLimited(FakeConnection $connection): bool
{
    // The pusher:error data payload is a JSON string inside the frame, so the
    // code arrives escaped; match the message instead.
    return collect($connection->messages)->contains(
        fn (string $message) => str_contains($message, 'Rate limit exceeded')
    );
}

/**
 * Read the raw attempt count for a limiter key from the array store.
 */
function limiterAttempts(string $key): mixed
{
    return app('cache')->store('array')->get($key);
}

it('rejects messages once the connection exhausts its quota', function () {
    $this->server->open($connection = new FakeConnection(remoteAddress: '203.0.113.10'));

    sendMessage($connection, 'public-one');
    sendMessage($connection, 'public-two');

    expect(wasRateLimited($connection))->toBeFalse();

    sendMessage($connection, 'public-three');

    expect(wasRateLimited($connection))->toBeTrue();
});

it('does not let a reconnecting client reset its quota', function () {
    $this->server->open($first = new FakeConnection(remoteAddress: '203.0.113.10'));

    sendMessage($first, 'public-one');
    sendMessage($first, 'public-two');

    $this->server->close($first);

    // Same client, brand new socket id.
    $this->server->open($second = new FakeConnection(remoteAddress: '203.0.113.10'));

    sendMessage($second, 'public-three');

    expect(wasRateLimited($second))->toBeTrue();
});

it('does not let a terminated client win back its quota by reconnecting', function () {
    $this->app['config']->set('reverb.apps.apps.0.rate_limiting.terminate_on_limit', true);

    $this->server->open($first = new FakeConnection(remoteAddress: '203.0.113.10'));

    sendMessage($first, 'public-one');
    sendMessage($first, 'public-two');
    sendMessage($first, 'public-three');

    expect($first->wasTerminated)->toBeTrue();

    $this->server->close($first);

    $this->server->open($second = new FakeConnection(remoteAddress: '203.0.113.10'));

    sendMessage($second, 'public-four');

    expect(wasRateLimited($second))->toBeTrue();
});

it('keeps quotas separate for different client addresses', function () {
    $this->server->open($first = new FakeConnection(remoteAddress: '203.0.113.10'));
    $this->server->open($second = new FakeConnection(remoteAddress: '198.51.100.7'));

    sendMessage($first, 'public-one');
    sendMessage($first, 'public-two');
    sendMessage($first, 'public-three');

    sendMessage($second, 'public-four');

    expect(wasRateLimited($first))->toBeTrue()
        ->and(wasRateLimited($second))->toBeFalse();
});

it('clears the connection limiter key on close', function () {
    $this->server->open($connection = new FakeConnection(remoteAddress: '203.0.113.10'));

    sendMessage($connection);

    $connectionKey = 'resonate:message:app-id:connection:'.$connection->id();

    expect(limiterAttempts($connectionKey))->toBe(1)
        ->and(limiterAttempts($connectionKey.':timer'))->not->toBeNull();

    $this->server->close($connection);

    expect(limiterAttempts($connectionKey))->toBeNull()
        ->and(limiterAttempts($connectionKey.':timer'))->toBeNull();
});

it('keeps the client limiter key on close', function () {
    $this->server->open($connection = new FakeConnection(remoteAddress: '203.0.113.10'));

    sendMessage($connection);

    $this->server->close($connection);

    // Releasing this one would hand a throttled client a fresh quota for the
    // price of a reconnect, which is the bug the client dimension exists for.
    expect(limiterAttempts('resonate:message:app-id:client:203.0.113.10'))->toBe(1);
});

it('falls back to the connection dimension when the transport reports no address', function () {
    // Documented caveat: without a remote address the quota is per connection,
    // so a reconnect does start over. Nothing accumulates, at least.
    $this->server->open($first = new FakeConnection);

    sendMessage($first, 'public-one');
    sendMessage($first, 'public-two');
    sendMessage($first, 'public-three');

    expect(wasRateLimited($first))->toBeTrue();

    $this->server->open($second = new FakeConnection);

    sendMessage($second, 'public-four');

    expect(wasRateLimited($second))->toBeFalse();
});

it('does not touch the limiter when rate limiting is disabled', function () {
    $this->app['config']->set('reverb.apps.apps.0.rate_limiting.enabled', false);

    $this->server->open($connection = new FakeConnection(remoteAddress: '203.0.113.10'));

    for ($i = 0; $i < 10; $i++) {
        sendMessage($connection, 'public-'.$i);
    }

    expect(wasRateLimited($connection))->toBeFalse()
        ->and(limiterAttempts('resonate:message:app-id:client:203.0.113.10'))->toBeNull();
});

it('refuses to build an application whose enabled rate limiting has no max_attempts', function () {
    // This used to hand `null` to tooManyAttempts(), which then rejected every
    // message after the second one with nothing in the logs to explain it.
    expect(fn () => new Application(
        'app-id',
        'app-key',
        'app-secret',
        60,
        30,
        ['*'],
        10_000,
        rateLimiting: ['enabled' => true, 'decay_seconds' => 60],
    ))->toThrow(InvalidConfiguration::class, 'max_attempts');
});

it('refuses to build an application whose enabled rate limiting has no decay_seconds', function () {
    expect(fn () => new Application(
        'app-id',
        'app-key',
        'app-secret',
        60,
        30,
        ['*'],
        10_000,
        rateLimiting: ['enabled' => true, 'max_attempts' => 60],
    ))->toThrow(InvalidConfiguration::class, 'decay_seconds');
});

it('accepts an application whose rate limiting is disabled and incomplete', function () {
    $application = new Application(
        'app-id',
        'app-key',
        'app-secret',
        60,
        30,
        ['*'],
        10_000,
        rateLimiting: ['enabled' => false],
    );

    expect($application->usesRateLimiting())->toBeFalse();
});

<?php

use Fledge\Async\Http\Server\Driver\Client;
use Fledge\Async\Http\Server\Request as FledgeRequest;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\ChannelsController;
use Webpatser\Resonate\Server\Router;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\Feature\Protocols\Pusher\Http\RequestSigner;

/*
 * Exercises ChannelsController: GET /apps/{appId}/channels. Ported from
 * Reverb's ChannelsControllerTest, adapted from the "boot a server and
 * HTTP-call it" style to invoking the controller directly with a signed
 * Server\Request. Channel state is seeded through the channel manager.
 */

if (! function_exists('signedChannelRequest')) {
    /**
     * Build a fledge request signed the way Pusher signs it.
     *
     * @param  array<string, string>  $query
     * @param  array<string, string>  $routeParams
     */
    function signedChannelRequest(string $method, string $path, array $query = [], string $body = '', array $routeParams = ['appId' => 'app-id']): FledgeRequest
    {
        $query += [
            'auth_key' => 'app-key',
            'auth_timestamp' => (string) time(),
            'auth_version' => '1.0',
        ];

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
}

if (! function_exists('subscribeToChannel')) {
    /**
     * Subscribe a fresh connection to the given channel.
     *
     * @param  array<string, mixed>  $userInfo
     */
    function subscribeToChannel(string $name, ?array $userInfo = null): FakeConnection
    {
        $channel = channels()->findOrCreate($name);
        $connection = new FakeConnection;

        if ($userInfo !== null) {
            $data = json_encode($userInfo);
            $channel->subscribe($connection, validAuth($connection->id(), $name, $data), $data);
        } else {
            $channel->subscribe($connection);
        }

        return $connection;
    }
}

it('can return all channel information', function () {
    subscribeToChannel('test-channel-one');
    subscribeToChannel('presence-test-channel-two', ['user_id' => 1]);

    $response = (new ChannelsController)->handleRequest(signedChannelRequest('GET', '/apps/app-id/channels', [
        'info' => 'user_count',
    ]));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"channels":{"test-channel-one":{},"presence-test-channel-two":{"user_count":1}}}');
});

it('can return filtered channels', function () {
    subscribeToChannel('test-channel-one');
    subscribeToChannel('presence-test-channel-two', ['user_id' => 1]);

    $response = (new ChannelsController)->handleRequest(signedChannelRequest('GET', '/apps/app-id/channels', [
        'filter_by_prefix' => 'presence-',
        'info' => 'user_count',
    ]));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"channels":{"presence-test-channel-two":{"user_count":1}}}');
});

it('returns empty results if no metrics requested', function () {
    subscribeToChannel('test-channel-one');
    subscribeToChannel('test-channel-two');

    $response = (new ChannelsController)->handleRequest(signedChannelRequest('GET', '/apps/app-id/channels'));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"channels":{"test-channel-one":{},"test-channel-two":{}}}');
});

it('only returns occupied channels', function () {
    subscribeToChannel('test-channel-one');
    subscribeToChannel('test-channel-two');

    $channels = channels();
    $connection = Illuminate\Support\Arr::first($channels->connections());
    $channels->unsubscribeFromAll($connection->connection());

    $response = (new ChannelsController)->handleRequest(signedChannelRequest('GET', '/apps/app-id/channels'));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"channels":{"test-channel-two":{}}}');
});

it('fails when using an invalid signature', function () {
    $request = new FledgeRequest(
        Mockery::mock(Client::class),
        'GET',
        League\Uri\Http::new('http://localhost/apps/app-id/channels?auth_signature=deadbeef'),
    );
    $request->setAttribute(Router::class, ['appId' => 'app-id']);

    $response = (new ChannelsController)->handleRequest($request);

    expect($response->getStatus())->toBe(401);
});

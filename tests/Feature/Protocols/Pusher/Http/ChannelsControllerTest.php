<?php

use Fledge\Async\Http\Server\Driver\Client;
use Fledge\Async\Http\Server\Request as FledgeRequest;
use Illuminate\Support\Arr;
use League\Uri\Http;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\ChannelsController;
use Webpatser\Resonate\Server\Router;
use Webpatser\Resonate\Tests\Feature\Protocols\Pusher\Http\RequestSigner;

/*
 * Exercises ChannelsController: GET /apps/{appId}/channels. Ported from
 * Reverb's ChannelsControllerTest, adapted from the "boot a server and
 * HTTP-call it" style to invoking the controller directly with a signed
 * Server\Request. Channel state is seeded through the channel manager. The
 * signedChannelRequest() and subscribeToChannel() helpers live in tests/Pest.php.
 */

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
    $connection = Arr::first($channels->connections());
    $channels->unsubscribeFromAll($connection->connection());

    $response = (new ChannelsController)->handleRequest(signedChannelRequest('GET', '/apps/app-id/channels'));

    expect($response->getStatus())->toBe(200)
        ->and(RequestSigner::body($response))->toBe('{"channels":{"test-channel-two":{}}}');
});

it('fails when using an invalid signature', function () {
    $request = new FledgeRequest(
        Mockery::mock(Client::class),
        'GET',
        Http::new('http://localhost/apps/app-id/channels?auth_signature=deadbeef'),
    );
    $request->setAttribute(Router::class, ['appId' => 'app-id']);

    $response = (new ChannelsController)->handleRequest($request);

    expect($response->getStatus())->toBe(401);
});

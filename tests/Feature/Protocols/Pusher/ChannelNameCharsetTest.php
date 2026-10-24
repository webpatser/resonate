<?php

use Webpatser\Resonate\Protocols\Pusher\ChannelName;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\ChannelController;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\ChannelUsersController;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\EventsBatchController;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\EventsController;
use Webpatser\Resonate\Protocols\Pusher\Server;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\Feature\Protocols\Pusher\Http\RequestSigner;

/*
 * Channel names are restricted to Pusher's charset on the socket and on the
 * REST API. Plugins embed the name in store keys and glob patterns, so a name
 * carrying a delimiter or a wildcard must never reach them.
 */

dataset('invalid channel names', [
    'colon' => 'room:1',
    'glob star' => 'presence-*',
    'glob bracket' => 'chat-[a-z]',
    'space' => 'my channel',
    'slash' => 'a/b',
    'newline' => "chat\nroom",
    'null byte' => "chat\0room",
    'hash outside the user prefix' => '#room',
    'unicode' => 'caf'."\u{e9}",
    'user prefix with an empty id' => '#server-to-user-',
    'user prefix with a control character' => "#server-to-user-a\nb",
]);

dataset('valid channel names', [
    'public' => 'orders',
    'private' => 'private-orders.1',
    'private encrypted' => 'private-encrypted-orders.1',
    'cache' => 'cache-orders',
    'every allowed symbol' => 'a_B-c=d@e,f.g;1',
    'user channel' => '#server-to-user-42',
    'user channel with an email id' => '#server-to-user-jane+tag@example.com',
]);

beforeEach(function () {
    $this->server = $this->app->make(Server::class);
});

/**
 * Send a raw pusher event for the given channel from the connection.
 */
function sendChannelEvent(FakeConnection $connection, string $event, string $channel): void
{
    test()->server->message($connection, json_encode([
        'event' => $event,
        'data' => ['channel' => $channel],
    ]));
}

/**
 * Determine whether the connection received a pusher 4200 error.
 */
function receivedInvalidMessage(FakeConnection $connection): bool
{
    return collect($connection->messages)->contains(
        fn (string $frame) => str_contains($frame, 'Invalid message format')
    );
}

it('rejects a subscribe to a channel name outside the charset', function (string $name) {
    $this->server->open($connection = new FakeConnection);

    sendChannelEvent($connection, 'pusher:subscribe', $name);

    expect(receivedInvalidMessage($connection))->toBeTrue()
        ->and(channels()->find($name))->toBeNull();
})->with('invalid channel names');

it('rejects an unsubscribe from a channel name outside the charset', function (string $name) {
    $this->server->open($connection = new FakeConnection);

    sendChannelEvent($connection, 'pusher:unsubscribe', $name);

    expect(receivedInvalidMessage($connection))->toBeTrue();
})->with('invalid channel names');

it('accepts a subscribe to a channel name inside the charset', function (string $name) {
    $this->server->open($connection = new FakeConnection);

    // Private channels need a signature, which is beside the point here: the
    // charset check runs first, so any error but 4200 proves the name passed.
    sendChannelEvent($connection, 'pusher:subscribe', $name);

    expect(receivedInvalidMessage($connection))->toBeFalse();
})->with('valid channel names');

it('accepts any channel name when validate_channel_names is disabled', function () {
    $this->app['config']->set('reverb.servers.reverb.validate_channel_names', false);

    $this->server->open($connection = new FakeConnection);

    sendChannelEvent($connection, 'pusher:subscribe', 'orders:42');

    expect(receivedInvalidMessage($connection))->toBeFalse()
        ->and(channels()->find('orders:42'))->not->toBeNull()
        ->and(ChannelName::isValid('orders:42'))->toBeTrue();
});

it('rejects a non-string channel name', function () {
    expect(ChannelName::isValid(['a']))->toBeFalse()
        ->and(ChannelName::isValid(null))->toBeFalse();
});

it('returns 400 from the channel endpoint for an invalid name', function (string $name) {
    $response = (new ChannelController)->handleRequest(RequestSigner::signed(
        'GET',
        '/apps/app-id/channels/x',
        routeParams: ['appId' => 'app-id', 'channel' => $name],
    ));

    expect($response->getStatus())->toBe(400);
})->with('invalid channel names');

it('serves the channel endpoint for a valid name', function (string $name) {
    $response = (new ChannelController)->handleRequest(RequestSigner::signed(
        'GET',
        '/apps/app-id/channels/x',
        routeParams: ['appId' => 'app-id', 'channel' => $name],
    ));

    expect($response->getStatus())->toBe(200);
})->with('valid channel names');

it('returns 400 from the channel users endpoint for an invalid name', function () {
    $response = (new ChannelUsersController)->handleRequest(RequestSigner::signed(
        'GET',
        '/apps/app-id/channels/x/users',
        routeParams: ['appId' => 'app-id', 'channel' => 'presence-*'],
    ));

    expect($response->getStatus())->toBe(400);
});

it('returns 422 from the events endpoint for an invalid channel', function (string $name) {
    $response = (new EventsController)->handleRequest(RequestSigner::post('/apps/app-id/events', [
        'name' => 'NewEvent',
        'channel' => $name,
        'data' => '{}',
    ]));

    expect($response->getStatus())->toBe(422);
})->with('invalid channel names');

it('returns 422 from the events endpoint when one of several channels is invalid', function () {
    $connection = new FakeConnection;
    RequestSigner::subscribe($connection, 'test-channel');

    $response = (new EventsController)->handleRequest(RequestSigner::post('/apps/app-id/events', [
        'name' => 'NewEvent',
        'channels' => ['test-channel', 'bad:channel'],
        'data' => '{}',
    ]));

    expect($response->getStatus())->toBe(422)
        ->and($connection->messages)->toBe([]);
});

it('returns 422 from the batch endpoint for an invalid channel', function (string $name) {
    $response = (new EventsBatchController)->handleRequest(RequestSigner::post('/apps/app-id/batch_events', [
        'batch' => [
            ['name' => 'NewEvent', 'channel' => $name, 'data' => '{}'],
        ],
    ]));

    expect($response->getStatus())->toBe(422);
})->with('invalid channel names');

it('accepts an event for the user channel prefix', function () {
    $response = (new EventsController)->handleRequest(RequestSigner::post('/apps/app-id/events', [
        'name' => 'NewEvent',
        'channel' => '#server-to-user-42',
        'data' => '{}',
    ]));

    expect($response->getStatus())->toBe(200);
});

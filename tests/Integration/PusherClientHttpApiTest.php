<?php

use Fledge\Async\Http\Server\Driver\Client as FledgeClient;
use Fledge\Async\Http\Server\Request as FledgeRequest;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response as GuzzlePsrResponse;
use Psr\Http\Message\RequestInterface;
use Pusher\ApiErrorException;
use Pusher\Pusher;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\EventsController;
use Webpatser\Resonate\Server\Router;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

use function Fledge\Async\Stream\buffer;

/*
 * Phase 3 milestone: drive the real pusher/pusher-php-server client against the
 * Resonate HTTP API. The Pusher client signs the request exactly as a host
 * app's stock broadcaster does; a Guzzle handler routes that signed request
 * into the EventsController, so this proves Resonate's HMAC verification
 * accepts genuine Pusher signatures and dispatches the event to channels.
 */

/**
 * A Guzzle client whose handler routes requests into the Resonate controllers.
 */
function resonateGuzzleClient(): GuzzleClient
{
    $handler = function (RequestInterface $request, array $options) {
        preg_match('#/apps/([^/]+)/events#', $request->getUri()->getPath(), $matches);

        $fledgeRequest = new FledgeRequest(
            Mockery::mock(FledgeClient::class),
            $request->getMethod(),
            League\Uri\Http::new((string) $request->getUri()),
            [],
            (string) $request->getBody(),
        );

        $fledgeRequest->setAttribute(Router::class, ['appId' => $matches[1] ?? null]);

        $response = (new EventsController)->handleRequest($fledgeRequest);

        return new FulfilledPromise(new GuzzlePsrResponse(
            $response->getStatus(),
            ['Content-Type' => 'application/json'],
            buffer($response->getBody()),
        ));
    };

    return new GuzzleClient(['handler' => HandlerStack::create($handler)]);
}

/**
 * A real Pusher client configured to talk to Resonate through the routing handler.
 */
function resonatePusher(string $secret = 'app-secret'): Pusher
{
    return new Pusher('app-key', $secret, 'app-id', [
        'host' => '127.0.0.1',
        'port' => 8080,
        'scheme' => 'http',
        'useTLS' => false,
    ], resonateGuzzleClient());
}

it('accepts and dispatches an event from the real Pusher client', function () {
    $connection = new FakeConnection;
    channels()->findOrCreate('orders')->subscribe($connection);

    $result = resonatePusher()->trigger('orders', 'OrderShipped', ['id' => 42]);

    expect($result)->toBeObject()
        ->and($connection->messages)->not->toBeEmpty()
        ->and($connection->messages[0])->toContain('OrderShipped')
        ->and($connection->messages[0])->toContain('orders');
});

it('rejects a Pusher request signed with the wrong secret', function () {
    channels()->findOrCreate('orders')->subscribe($connection = new FakeConnection);

    expect(fn () => resonatePusher('wrong-secret')->trigger('orders', 'OrderShipped', ['id' => 42]))
        ->toThrow(ApiErrorException::class);

    expect($connection->messages)->toBeEmpty();
})->group('integration');

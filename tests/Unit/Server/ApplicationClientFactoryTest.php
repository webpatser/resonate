<?php

use Fledge\Async\Http\Server\Driver\Client;
use Fledge\Async\Http\Server\Request as FledgeRequest;
use Fledge\Async\WebSocket\Parser\Rfc6455ParserFactory;
use Fledge\Async\WebSocket\Parser\WebsocketFrameHandler;
use Fledge\Async\WebSocket\Parser\WebsocketFrameType;
use Fledge\Async\WebSocket\Parser\WebsocketParserException;
use Fledge\Async\WebSocket\WebsocketCloseCode;
use Illuminate\Support\Collection;
use League\Uri\Http;
use Webpatser\Resonate\ConfigApplicationProvider;
use Webpatser\Resonate\Server\ApplicationClientFactory;
use Webpatser\Resonate\Server\Router;

/*
 * Exercises ApplicationClientFactory: the per-connection websocket message
 * size limit.
 *
 * The transport limit used to be one global number, set to the largest
 * configured application's `max_message_size`. A client of an app limited to
 * 10KB could therefore make the parser buffer up to the largest app's limit
 * (10MB here) before Pusher\Server::message() rejected it on the assembled
 * string, then do it again. These tests pin that each connection's parser is
 * built from its own application's limit, and that the rejection happens on
 * the frame header, before any payload byte is buffered.
 *
 * The full createClient() path needs a live upgraded socket, so the internal
 * resolution methods are promoted to public by a thin test subclass, in the
 * same style as WebSocketHandlerTest.
 */

/**
 * Two applications sharing a server: one small limit, one very large.
 */
function twoAppsWithDifferentLimits(): ConfigApplicationProvider
{
    return new ConfigApplicationProvider(Collection::make([
        [
            'key' => 'small-key',
            'secret' => 'small-secret',
            'app_id' => 'small-app',
            'ping_interval' => 60,
            'allowed_origins' => ['*'],
            'max_message_size' => 10_000,
        ],
        [
            'key' => 'large-key',
            'secret' => 'large-secret',
            'app_id' => 'large-app',
            'ping_interval' => 60,
            'allowed_origins' => ['*'],
            'max_message_size' => 10_000_000,
        ],
    ]));
}

/**
 * Build the factory under test with its internals promoted to public.
 */
function clientFactory(int $fallbackMessageSize = 10_000): ApplicationClientFactory
{
    return new class(twoAppsWithDifferentLimits(), $fallbackMessageSize) extends ApplicationClientFactory
    {
        public function callMessageSizeFor(FledgeRequest $request): int
        {
            return $this->messageSizeFor($request);
        }

        public function callParserFactoryFor(FledgeRequest $request): Rfc6455ParserFactory
        {
            return $this->parserFactoryFor($request);
        }
    };
}

/**
 * Build an upgrade request for the given app key, as the router hands it over.
 */
function upgradeRequest(?string $appKey): FledgeRequest
{
    $path = $appKey === null ? '/somewhere/else' : "/app/{$appKey}";

    $request = new FledgeRequest(
        Mockery::mock(Client::class),
        'GET',
        Http::new('http://localhost'.$path),
    );

    if ($appKey !== null) {
        $request->setAttribute(Router::class, ['appKey' => $appKey]);
    }

    return $request;
}

/**
 * Build the header of a masked client text frame declaring the given length.
 *
 * The parser enforces its size limits the moment it has read the declared
 * length, before the masking key and before a single payload byte, which is
 * exactly the property under test.
 */
function frameHeaderDeclaring(int $length): string
{
    return chr(0x81).chr(0x80 | 127).pack('J', $length);
}

/**
 * Push the given bytes through a parser built by the factory for a request.
 */
function pushFrameHeader(ApplicationClientFactory $factory, FledgeRequest $request, int $length): void
{
    $parser = $factory->callParserFactoryFor($request)->createParser(
        new class implements WebsocketFrameHandler
        {
            public function handleFrame(WebsocketFrameType $frameType, string $data, bool $isFinal): void {}
        },
        masked: false,
    );

    $parser->push(frameHeaderDeclaring($length));
}

it('sizes a connection parser from its own application limit', function () {
    $factory = clientFactory();

    expect($factory->callMessageSizeFor(upgradeRequest('small-key')))->toBe(10_000)
        ->and($factory->callMessageSizeFor(upgradeRequest('large-key')))->toBe(10_000_000);
});

it('rejects a frame that exceeds the small application limit', function () {
    $factory = clientFactory();

    // Comfortably above the small app's 10KB limit, comfortably below both the
    // large app's limit and the parser's 1MB per-frame ceiling, so this can
    // only be the message size limit talking.
    pushFrameHeader($factory, upgradeRequest('small-key'), 50_000);
})->throws(WebsocketParserException::class);

it('rejects with the message too large close code', function () {
    $factory = clientFactory();

    try {
        pushFrameHeader($factory, upgradeRequest('small-key'), 50_000);
    } catch (WebsocketParserException $e) {
        expect($e->getCode())->toBe(WebsocketCloseCode::MESSAGE_TOO_LARGE);

        return;
    }

    $this->fail('The parser accepted a frame larger than the application limit.');
});

it('accepts the same frame for an application that allows it', function () {
    $factory = clientFactory();

    pushFrameHeader($factory, upgradeRequest('large-key'), 50_000);

    expect(true)->toBeTrue();
});

it('does not let one application raise another application limit', function () {
    $factory = clientFactory();

    // Warm the large app's parser factory first: a shared factory would hand
    // the 10MB limit to the small app's connection too.
    pushFrameHeader($factory, upgradeRequest('large-key'), 50_000);

    expect(fn () => pushFrameHeader($factory, upgradeRequest('small-key'), 50_000))
        ->toThrow(WebsocketParserException::class);
});

it('falls back to the configured fallback for an unknown application key', function () {
    $factory = clientFactory(fallbackMessageSize: 10_000);

    expect($factory->callMessageSizeFor(upgradeRequest('nope-key')))->toBe(10_000)
        ->and($factory->callMessageSizeFor(upgradeRequest(null)))->toBe(10_000);
});

it('reuses one parser factory per limit', function () {
    $factory = clientFactory();

    expect($factory->callParserFactoryFor(upgradeRequest('small-key')))
        ->toBe($factory->callParserFactoryFor(upgradeRequest('small-key')))
        ->and($factory->callParserFactoryFor(upgradeRequest('small-key')))
        ->not->toBe($factory->callParserFactoryFor(upgradeRequest('large-key')));
});

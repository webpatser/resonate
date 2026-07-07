<?php

use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Protocols\Pusher\EventDispatcher;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\EventsBatchController;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\EventsController;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\Feature\Protocols\Pusher\Http\RequestSigner;

/**
 * A pub/sub provider that records every published envelope.
 */
class RecordingPubSubProvider implements PubSubProvider
{
    /** @var array<int, array<string, mixed>> */
    public array $published = [];

    public function connect(): void {}

    public function disconnect(): void {}

    public function subscribe(): void {}

    public function on(string $event, callable $callback): void {}

    public function listen(string $event, callable $callback): void {}

    public function stopListening(string $event): void {}

    public function publish(array $payload): void
    {
        $this->published[] = $payload;
    }
}

/**
 * A server provider that publishes events.
 */
class PublishingServerProvider extends ServerProvider
{
    public function shouldPublishEvents(): bool
    {
        return true;
    }
}

/**
 * A server provider that does not publish events.
 */
class LocalOnlyServerProvider extends ServerProvider
{
    //
}

beforeEach(function () {
    $this->pubSub = new RecordingPubSubProvider;
    $this->app->instance(PubSubProvider::class, $this->pubSub);
    $this->app->instance(ServerProvider::class, new PublishingServerProvider);

    $this->application = app(ApplicationProvider::class)->findById('app-id');
});

it('publishes a JSON message envelope when scaling is enabled', function () {
    EventDispatcher::dispatch($this->application, [
        'channel' => 'test-channel',
        'event' => 'App\\Events\\Test',
        'data' => [],
    ]);

    expect($this->pubSub->published)->toHaveCount(1);

    $envelope = $this->pubSub->published[0];

    expect($envelope['type'])->toBe('message');
    expect($envelope['payload'])->toBe([
        'channel' => 'test-channel',
        'event' => 'App\\Events\\Test',
        'data' => [],
    ]);
});

it('carries the application as its id string, not a serialized blob', function () {
    EventDispatcher::dispatch($this->application, [
        'channel' => 'test-channel',
        'event' => 'x',
        'data' => [],
    ]);

    $envelope = $this->pubSub->published[0];

    expect($envelope['application'])->toBe('app-id');
    expect($envelope['application'])->toBeString();

    // Not a PHP serialized object payload.
    expect($envelope['application'])->not->toStartWith('O:');

    // The whole envelope round-trips through JSON cleanly.
    $json = json_encode($envelope, JSON_THROW_ON_ERROR);
    expect(json_decode($json, true))->toBe($envelope);
});

it('carries an explicit socket id in the envelope when the excluded connection is not local', function () {
    EventDispatcher::dispatch($this->application, [
        'channel' => 'test-channel',
        'event' => 'x',
        'data' => [],
    ], null, '123.456');

    expect($this->pubSub->published[0]['socket_id'])->toBe('123.456');
});

it('falls back to the local connection id when no explicit socket id is given', function () {
    $connection = new FakeConnection;

    EventDispatcher::dispatch($this->application, [
        'channel' => 'test-channel',
        'event' => 'x',
        'data' => [],
    ], $connection);

    expect($this->pubSub->published[0]['socket_id'])->toBe($connection->id());
});

it('keeps toOthers working across servers for HTTP events', function () {
    $response = (new EventsController)->handleRequest(RequestSigner::post('/apps/app-id/events', [
        'name' => 'NewEvent',
        'channel' => 'test-channel',
        'data' => json_encode(['some' => 'data']),
        'socket_id' => '123.456',
    ]));

    expect($response->getStatus())->toBe(200)
        ->and($this->pubSub->published)->toHaveCount(1)
        ->and($this->pubSub->published[0]['socket_id'])->toBe('123.456');
});

it('keeps toOthers working across servers for HTTP batch events', function () {
    $response = (new EventsBatchController)->handleRequest(RequestSigner::post('/apps/app-id/batch_events', ['batch' => [
        [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
            'socket_id' => '123.456',
        ],
    ]]));

    expect($response->getStatus())->toBe(200)
        ->and($this->pubSub->published)->toHaveCount(1)
        ->and($this->pubSub->published[0]['socket_id'])->toBe('123.456');
});

it('does not publish when the server should not publish events', function () {
    $this->app->instance(ServerProvider::class, new LocalOnlyServerProvider);

    EventDispatcher::dispatch($this->application, [
        'channel' => 'test-channel',
        'event' => 'x',
        'data' => [],
    ]);

    expect($this->pubSub->published)->toBeEmpty();
});

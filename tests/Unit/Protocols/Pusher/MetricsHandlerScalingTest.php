<?php

use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\Protocols\Pusher\MetricType;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\Fakes\FakePubSubBus;
use Webpatser\Resonate\Tests\Fakes\ScaledServerProvider;

/*
 * Exercises MetricsHandler's scaled gather path: the node identity that keeps
 * a node from answering (and so counting) itself, and the early completion
 * that stops every scaled metrics request paying the full collection window.
 */

beforeEach(function () {
    $this->bus = new FakePubSubBus;

    $this->app->instance(PubSubProvider::class, $this->bus);
    $this->app->instance(ServerProvider::class, new ScaledServerProvider);

    $this->application = app(ApplicationProvider::class)->all()->first();
});

/**
 * Build a metrics handler wired to the bus as this node.
 */
function scaledMetrics(float $collectionWindow = 5.0): MetricsHandler
{
    $handler = new MetricsHandler(app(ChannelManager::class), $collectionWindow);

    // Presence channels gather through the container while subscribing.
    app()->instance(MetricsHandler::class, $handler);

    // Redis delivers a node its own publications: the publisher and the
    // subscriber are separate connections on the same channel.
    test()->bus->subscribers[] = fn (array $envelope) => $handler->publish([
        'application' => test()->application,
        'payload' => $envelope['payload'],
    ]);

    return $handler;
}

/**
 * Attach a sibling node that answers every request with the given metrics.
 *
 * @param  array<string|int, mixed>  $metrics
 */
function siblingNode(array $metrics, string $nodeId = 'sibling-node'): void
{
    test()->bus->receivers++;

    test()->bus->subscribers[] = function (array $envelope) use ($metrics, $nodeId) {
        if (! isset($envelope['payload']['type'])) {
            return;
        }

        test()->bus->publish([
            'type' => 'metrics',
            'application' => 'app-id',
            'payload' => [
                'key' => $envelope['payload']['key'],
                'node' => $nodeId,
                'metrics' => $metrics,
            ],
        ]);
    };
}

/**
 * Subscribe a presence connection carrying the given user id.
 */
function presenceUser(string $channel, int $id): FakeConnection
{
    $connection = new FakeConnection;
    $data = json_encode(['user_id' => $id, 'user_info' => ['name' => "User {$id}"]]);

    channels()->findOrCreate($channel)->subscribe(
        $connection,
        validAuth($connection->id(), $channel, $data),
        $data,
    );

    return $connection;
}

it('stamps every metrics request with this node identity', function () {
    $handler = scaledMetrics();

    $handler->gather($this->application, 'channels');

    expect($this->bus->requests())->toHaveCount(1)
        ->and($this->bus->requests()[0]['payload']['node'])->toBe($handler->nodeId())
        ->and($handler->nodeId())->not->toBe('');
});

it('never answers its own metrics request', function () {
    $handler = scaledMetrics();

    openConnection('test-channel');

    $handler->gather($this->application, 'channels', ['info' => 'subscription_count']);

    // The request came straight back off the bus. Answering it would buffer a
    // reply that the explicit local append then duplicates.
    expect($this->bus->replies())->toBeEmpty();
});

it('counts each node once when merging a channel across two nodes', function () {
    $handler = scaledMetrics();

    siblingNode(['occupied' => true, 'subscription_count' => 5]);

    foreach (range(1, 5) as $ignored) {
        openConnection('test-channel');
    }

    $metrics = $handler->gather($this->application, 'channel', [
        'channel' => 'test-channel',
        'info' => 'occupied,subscription_count',
    ]);

    expect($metrics)->toBe(['occupied' => true, 'subscription_count' => 10]);
});

it('counts each node once when merging channels across two nodes', function () {
    $handler = scaledMetrics();

    siblingNode(['test-channel' => ['occupied' => true, 'subscription_count' => 5]]);

    foreach (range(1, 5) as $ignored) {
        openConnection('test-channel');
    }

    $metrics = $handler->gather($this->application, 'channels', ['info' => 'occupied,subscription_count']);

    expect($metrics)->toBe([
        'test-channel' => ['occupied' => true, 'subscription_count' => 10],
    ]);
});

it('counts each node once when merging channel users across two nodes', function () {
    $handler = scaledMetrics();

    siblingNode([['id' => 3], ['id' => 4]]);

    presenceUser('presence-test-channel', 1);
    presenceUser('presence-test-channel', 2);

    $users = $handler->gather($this->application, 'channel_users', [
        'channel' => 'presence-test-channel',
    ]);

    expect($users)->toBe([['id' => 3], ['id' => 4], ['id' => 1], ['id' => 2]]);
});

it('counts each node once when merging connections across two nodes', function () {
    $handler = scaledMetrics();

    siblingNode(['sibling-socket' => ['id' => 'sibling-socket']]);

    $local = openConnection('test-channel');

    $connections = $handler->gather($this->application, 'connections');

    expect($connections)->toHaveCount(2)
        ->and(array_keys($connections))->toEqualCanonicalizing(['sibling-socket', $local->id()]);
});

it('completes as soon as every expected reply has landed', function () {
    $handler = scaledMetrics(collectionWindow: 5.0);

    siblingNode(['occupied' => true, 'subscription_count' => 1]);

    openConnection('test-channel');

    $startedAt = hrtime(true);

    $metrics = $handler->gather($this->application, 'channel', [
        'channel' => 'test-channel',
        'info' => 'occupied,subscription_count',
    ]);

    $elapsed = (hrtime(true) - $startedAt) / 1e9;

    expect($metrics['subscription_count'])->toBe(2)
        ->and($elapsed)->toBeLessThan(1.0);
});

it('resolves at the timeout when a node never replies', function () {
    $handler = scaledMetrics(collectionWindow: 0.25);

    // A subscriber received the request but no sibling is wired up to answer.
    $this->bus->receivers = 2;

    openConnection('test-channel');

    $startedAt = hrtime(true);

    $metrics = $handler->gather($this->application, 'channel', [
        'channel' => 'test-channel',
        'info' => 'occupied,subscription_count',
    ]);

    $elapsed = (hrtime(true) - $startedAt) / 1e9;

    expect($metrics)->toBe(['occupied' => true, 'subscription_count' => 1])
        ->and($elapsed)->toBeGreaterThanOrEqual(0.25)
        ->and($elapsed)->toBeLessThan(2.0);
});

it('does not wait at all when no sibling received the request', function () {
    $handler = scaledMetrics(collectionWindow: 5.0);

    openConnection('test-channel');

    $startedAt = hrtime(true);

    $metrics = $handler->gather($this->application, 'channel', [
        'channel' => 'test-channel',
        'info' => 'occupied,subscription_count',
    ]);

    $elapsed = (hrtime(true) - $startedAt) / 1e9;

    expect($metrics)->toBe(['occupied' => true, 'subscription_count' => 1])
        ->and($elapsed)->toBeLessThan(1.0);
});

it('answers a request that carries another node identity', function () {
    $handler = scaledMetrics();

    openConnection('test-channel');

    $handler->publish([
        'application' => $this->application,
        'payload' => [
            'key' => 'request-id',
            'node' => 'another-node',
            'type' => 'channels',
            'options' => ['info' => 'subscription_count'],
        ],
    ]);

    expect($this->bus->replies())->toHaveCount(1)
        ->and($this->bus->replies()[0]['payload'])->toMatchArray([
            'key' => 'request-id',
            'node' => $handler->nodeId(),
        ])
        ->and($this->bus->replies()[0]['payload']['metrics'])
        ->toBe(['test-channel' => ['subscription_count' => 1]]);
});

it('drops a reply for a request it is not awaiting', function () {
    $handler = scaledMetrics();

    $handler->publish([
        'application' => $this->application,
        'payload' => ['key' => 'unknown-request', 'metrics' => ['occupied' => true]],
    ]);

    expect($this->bus->published)->toBeEmpty();
});

it('reads local metrics directly when scaling is disabled', function () {
    $this->app->forgetInstance(ServerProvider::class);

    $handler = new MetricsHandler(app(ChannelManager::class));

    openConnection('test-channel');

    expect($handler->gather($this->application, 'channel', [
        'channel' => 'test-channel',
        'info' => 'occupied,subscription_count',
    ]))->toBe(['occupied' => true, 'subscription_count' => 1])
        ->and($this->bus->published)->toBeEmpty();
});

it('answers presence_connections with the connections of one user on this node', function () {
    $handler = scaledMetrics();

    $first = presenceUser('presence-test-channel', 1);
    $second = presenceUser('presence-test-channel', 1);
    presenceUser('presence-test-channel', 2);

    $connections = $handler->local($this->application, MetricType::PRESENCE_CONNECTIONS, [
        'channel' => 'presence-test-channel',
        'user_id' => '1',
    ]);

    expect($connections)->toHaveCount(2)
        ->and(array_column($connections, 'id'))->toEqualCanonicalizing([$first->id(), $second->id()])
        ->and($connections[0]['subscribed_at'])->toBeFloat()
        ->and($connections[1]['subscribed_at'])->toBeFloat();
});

it('answers presence_connections for an unknown channel with an empty list', function () {
    $handler = scaledMetrics();

    expect($handler->local($this->application, MetricType::PRESENCE_CONNECTIONS, [
        'channel' => 'presence-missing-channel',
        'user_id' => '1',
    ]))->toBe([]);
});

it('merges presence data across two nodes', function () {
    $handler = scaledMetrics();

    siblingNode(['presence' => [
        'count' => 2,
        'ids' => [1, 3],
        'hash' => [1 => ['name' => 'User 1'], 3 => []],
    ]]);

    presenceUser('presence-test-channel', 1);
    presenceUser('presence-test-channel', 2);

    $data = $handler->gather($this->application, 'presence_data', ['channel' => 'presence-test-channel']);

    expect($data['presence']['count'])->toBe(3)
        ->and($data['presence']['ids'])->toBe([1, 3, 2])
        ->and(json_encode($data['presence']['hash']))
        ->toBe('{"1":{"name":"User 1"},"3":{},"2":{"name":"User 2"}}');
});

it('merges channel users across two nodes into a list without duplicates', function () {
    $handler = scaledMetrics();

    siblingNode([['id' => 1], ['id' => 3]]);

    presenceUser('presence-test-channel', 1);
    presenceUser('presence-test-channel', 2);

    $users = $handler->gather($this->application, 'channel_users', [
        'channel' => 'presence-test-channel',
    ]);

    expect(array_is_list($users))->toBeTrue()
        ->and($users)->toBe([['id' => 1], ['id' => 3], ['id' => 2]]);
});

<?php

use Fledge\Async\DeferredFuture;
use Webpatser\Resonate\Application;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Plugins\Contracts\ConnectionLifecycle;
use Webpatser\Resonate\Plugins\Contracts\ServerPlugin;
use Webpatser\Resonate\Plugins\PluginContext;
use Webpatser\Resonate\Plugins\PluginManager;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\EventDispatcher;
use Webpatser\Resonate\Protocols\Pusher\EventHandler;
use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\Protocols\Pusher\Server;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\Fakes\FakePubSubBus;
use Webpatser\Resonate\Tests\Fakes\ScaledServerProvider;

use function Fledge\Async\async;
use function Fledge\Async\Future\await;

/*
 * A subscription on a scaled presence channel can suspend twice after the
 * connection joins: while the fleet is asked about the user and its members,
 * and inside a plugin's onSubscribe. Broadcasts landing in either window are
 * held back and delivered, once and in arrival order, only after
 * `subscription_succeeded` and every plugin's onSubscribe are out.
 */

beforeEach(function () {
    $this->bus = new FakePubSubBus;

    $this->app->instance(PubSubProvider::class, $this->bus);
    $this->app->instance(ServerProvider::class, new ScaledServerProvider);

    // What the rest of the fleet answers, set per test.
    $this->fleet = fn () => [];

    $application = app(ApplicationProvider::class)->all()->first();

    $metrics = new MetricsHandler(app(ChannelManager::class));

    $this->app->instance(MetricsHandler::class, $metrics);

    // Redis delivers a node its own publications, and one sibling node
    // answers every metrics request with what the fleet closure returns.
    // The real handler then merges that reply with this node's own view.
    $this->bus->receivers = 2;

    $this->bus->subscribers[] = fn (array $envelope) => $metrics->publish([
        'application' => $application,
        'payload' => $envelope['payload'],
    ]);

    $this->bus->subscribers[] = function (array $envelope) use ($application) {
        $request = $envelope['payload'] ?? [];

        if (! isset($request['type'], $request['key'])) {
            return;
        }

        $this->bus->publish([
            'type' => 'metrics',
            'application' => $application->id(),
            'payload' => [
                'key' => $request['key'],
                'node' => 'sibling-node',
                'metrics' => ($this->fleet)($application, $request['type'], $request['options'] ?? []),
            ],
        ]);
    };

    $this->handler = app(EventHandler::class);
});

/**
 * Subscribe a connection to a presence channel through `pusher:subscribe`.
 */
function joinRoom(FakeConnection $connection, int $userId, string $name = 'Joe', string $channel = 'presence-room'): FakeConnection
{
    $data = (string) json_encode(['user_id' => $userId, 'user_info' => ['name' => $name]]);

    test()->handler->handle($connection, 'pusher:subscribe', [
        'channel' => $channel,
        'auth' => validAuth($connection->id(), $channel, $data),
        'channel_data' => $data,
    ]);

    return $connection;
}

/**
 * Register a lifecycle plugin that records its hooks and runs the given onSubscribe work.
 *
 * @param  (Closure(Connection, Channel): void)|null  $onSubscribe
 */
function lifecyclePlugin(?Closure $onSubscribe = null): ConnectionLifecycle
{
    $plugin = new class($onSubscribe) implements ConnectionLifecycle, ServerPlugin
    {
        /** @var array<int, string> */
        public array $hooks = [];

        public function __construct(protected ?Closure $work) {}

        public function boot(PluginContext $context): void {}

        public function onOpen(Connection $connection): void {}

        public function onClose(Connection $connection): void
        {
            $this->hooks[] = 'onClose';
        }

        public function onSubscribe(Connection $connection, Channel $channel): void
        {
            $this->hooks[] = 'onSubscribe '.$channel->name();

            if ($this->work !== null) {
                ($this->work)($connection, $channel);
            }
        }

        public function onUnsubscribe(Connection $connection, Channel $channel): void
        {
            $this->hooks[] = 'onUnsubscribe '.$channel->name();
        }
    };

    app(PluginManager::class)->register($plugin);

    return $plugin;
}

/**
 * Format a `pusher_internal:*` frame the way the event handler sends it.
 *
 * @param  array<string, mixed>  $data
 */
function internalFrame(string $event, array $data, string $channel = 'presence-room'): string
{
    return (string) json_encode([
        'event' => 'pusher_internal:'.$event,
        'data' => json_encode((object) $data),
        'channel' => $channel,
    ]);
}

/**
 * Format a frame on the presence channel.
 */
function roomFrame(string $event, string $data): string
{
    return (string) json_encode(['event' => $event, 'channel' => 'presence-room', 'data' => $data]);
}

/**
 * Broadcast a frame to the presence channel the way an inbound bus message is delivered.
 */
function broadcastToRoom(string $data): void
{
    EventDispatcher::dispatchSynchronously(
        (new FakeConnection)->app(),
        ['event' => 'live', 'channel' => 'presence-room', 'data' => $data],
    );
}

/**
 * Take the connection out of every channel, the given way.
 */
function leaveEverything(FakeConnection $connection, string $how): void
{
    match ($how) {
        'unsubscribeFromAll' => channels()->unsubscribeFromAll($connection),
        'close' => app(Server::class)->close($connection),
    };
}

/**
 * Get the member events published on the bus.
 *
 * @return array<int, array<string, mixed>>
 */
function busMemberEvents(string $event): array
{
    return array_values(array_filter(
        test()->bus->published,
        fn (array $envelope) => ($envelope['payload']['event'] ?? null) === "pusher_internal:{$event}",
    ));
}

/**
 * Get the `subscription_succeeded` frames the connection received.
 *
 * @return array<int, string>
 */
function confirmationsOf(FakeConnection $connection): array
{
    return array_values(array_filter(
        $connection->messages,
        fn (string $message) => (json_decode($message, true)['event'] ?? null) === 'pusher_internal:subscription_succeeded',
    ));
}

it('holds broadcasts to a joiner until subscription_succeeded and every onSubscribe returned', function () {
    $present = joinRoom(new FakeConnection, 2, 'Present');
    $joiner = new FakeConnection;
    $gate = new DeferredFuture;

    // Registered before the replay plugin, and suspends in onSubscribe.
    lifecyclePlugin(function (Connection $connection) use ($gate) {
        $connection->send(roomFrame('slow', 'before'));

        $gate->getFuture()->await();

        $connection->send(roomFrame('slow', 'after'));
    });

    lifecyclePlugin(fn (Connection $connection) => $connection->send(roomFrame('replay', 'missed')));

    $sibling = ['presence' => ['count' => 2, 'ids' => [1, 3], 'hash' => [1 => ['name' => 'Joe'], 3 => ['name' => 'Sibling']]]];

    $this->fleet = function (Application $app, string $type) use ($sibling) {
        if ($type === 'presence_connections') {
            broadcastToRoom('during the fleet query');

            return [];
        }

        return $sibling;
    };

    $join = async(fn () => joinRoom($joiner, 1));

    drainLoop();

    // The sibling's members first, then this node's own.
    $confirmation = internalFrame('subscription_succeeded', ['presence' => [
        'count' => 3,
        'ids' => [1, 3, 2],
        'hash' => [1 => ['name' => 'Joe'], 3 => ['name' => 'Sibling'], 2 => ['name' => 'Present']],
    ]]);

    // The slow plugin is suspended in onSubscribe.
    expect($joiner->messages)->toBe([$confirmation, roomFrame('slow', 'before')]);

    broadcastToRoom('during onSubscribe');

    expect($joiner->messages)->toHaveCount(2);

    $gate->complete();

    $join->await();

    broadcastToRoom('after');

    expect($joiner->messages)->toBe([
        $confirmation,
        roomFrame('slow', 'before'),
        roomFrame('slow', 'after'),
        roomFrame('replay', 'missed'),
        roomFrame('live', 'during the fleet query'),
        roomFrame('live', 'during onSubscribe'),
        roomFrame('live', 'after'),
    ]);

    // The member already present was not held back.
    expect(array_slice($present->messages, -3))->toBe([
        roomFrame('live', 'during the fleet query'),
        roomFrame('live', 'during onSubscribe'),
        roomFrame('live', 'after'),
    ]);
});

it('still announces a user present elsewhere when the join is cancelled mid-query', function (string $how, float $elsewhereAt) {
    $joiner = new FakeConnection;
    $asked = 0;

    $elsewhere = ['id' => 'elsewhere', 'subscribed_at' => $elsewhereAt];

    $this->fleet = function (Application $app, string $type) use ($joiner, $how, $elsewhere, &$asked) {
        if ($type !== 'presence_connections') {
            return [];
        }

        if (++$asked === 1) {
            leaveEverything($joiner, $how);

            return [['id' => $joiner->id(), 'subscribed_at' => 1.0], $elsewhere];
        }

        return [$elsewhere];
    };

    joinRoom($joiner, 1);

    expect(busMemberEvents('member_added'))->toHaveCount(1)
        ->and(busMemberEvents('member_removed'))->toBeEmpty()
        ->and($joiner->messages)->toBe([]);
})->with(['unsubscribeFromAll', 'close'])->with([
    'joined elsewhere later' => 2.0,
    'joined elsewhere earlier' => 0.5,
]);

it('does not announce a cancelled join when the user is present nowhere', function (string $how) {
    $joiner = new FakeConnection;
    $asked = 0;

    // The joiner is the user's first connection, then leaves mid-query.
    $this->fleet = function (Application $app, string $type) use ($joiner, $how, &$asked) {
        if ($type !== 'presence_connections') {
            return [];
        }

        if (++$asked === 1) {
            leaveEverything($joiner, $how);

            return [['id' => $joiner->id(), 'subscribed_at' => 1.0]];
        }

        return [];
    };

    joinRoom($joiner, 1);

    expect(busMemberEvents('member_added'))->toBeEmpty()
        ->and($joiner->messages)->toBe([]);
})->with(['unsubscribeFromAll', 'close']);

it('confirms both of two concurrent subscribes of one socket and delivers a held broadcast once after them', function () {
    $gate = new DeferredFuture;
    $waited = false;

    // The first question to the fleet stays out until the gate opens.
    $this->fleet = function (Application $app, string $type) use ($gate, &$waited) {
        if ($type === 'presence_connections' && ! $waited) {
            $waited = true;

            $gate->getFuture()->await();
        }

        return [];
    };

    $connection = new FakeConnection;

    $joins = [
        async(fn () => joinRoom($connection, 1)),
        async(fn () => joinRoom($connection, 1)),
    ];

    drainLoop();

    broadcastToRoom('during');

    $gate->complete();

    await($joins);

    $confirmation = internalFrame('subscription_succeeded', ['presence' => [
        'count' => 1,
        'ids' => [1],
        'hash' => [1 => ['name' => 'Joe']],
    ]]);

    expect($connection->messages)->toBe([$confirmation, $confirmation, roomFrame('live', 'during')])
        ->and(channels()->find('presence-room')->subscribed($connection))->toBeTrue()
        ->and($connection->hasState(EventHandler::SUBSCRIBING))->toBeFalse()
        ->and(busMemberEvents('member_added'))->toHaveCount(1);
});

it('drops a subscribe overtaken by its own unsubscribe without telling the plugins', function () {
    joinRoom(new FakeConnection, 2, 'Present');

    $this->bus->published = [];

    $recorder = lifecyclePlugin();

    $joiner = new FakeConnection;
    $gate = new DeferredFuture;
    $waited = false;

    $this->fleet = function (Application $app, string $type) use ($gate, &$waited) {
        if ($type === 'presence_connections' && ! $waited) {
            $waited = true;

            $gate->getFuture()->await();
        }

        return [];
    };

    $join = async(fn () => joinRoom($joiner, 1));

    drainLoop();

    broadcastToRoom('during');

    $this->handler->handle($joiner, 'pusher:unsubscribe', ['channel' => 'presence-room']);

    $gate->complete();

    $join->await();

    $channel = channels()->find('presence-room');

    expect($joiner->messages)->toBe([])
        ->and($channel->subscribed($joiner))->toBeFalse()
        ->and($joiner->hasState(EventHandler::SUBSCRIBING))->toBeFalse()
        ->and($recorder->hooks)->toBe([])
        ->and(busMemberEvents('member_added'))->toBeEmpty();

    // Subscribing again starts from a clean slate: the dropped broadcast stays dropped.
    joinRoom($joiner, 1);

    expect($joiner->messages)->toHaveCount(1)
        ->and(json_decode($joiner->messages[0], true)['event'])->toBe('pusher_internal:subscription_succeeded')
        ->and($recorder->hooks)->toBe(['onSubscribe presence-room']);
});

it('still tells the plugins of an unsubscribe after the subscribe was announced', function () {
    $recorder = lifecyclePlugin();

    $connection = joinRoom(new FakeConnection, 1);

    $this->handler->handle($connection, 'pusher:unsubscribe', ['channel' => 'presence-room']);

    expect($recorder->hooks)->toBe([
        'onSubscribe presence-room',
        'onUnsubscribe presence-room',
    ]);
});

it('confirms only the resubscribe of a socket that left and rejoined while its first subscribe was in flight', function (bool $keptAlive) {
    $present = $keptAlive ? joinRoom(new FakeConnection, 2, 'Present') : null;

    $recorder = lifecyclePlugin();

    $joiner = new FakeConnection;
    $gate = new DeferredFuture;
    $waited = false;

    // The first subscribe's question to the fleet stays out until the gate opens.
    $this->fleet = function (Application $app, string $type) use ($gate, &$waited) {
        if ($type === 'presence_connections' && ! $waited) {
            $waited = true;

            $gate->getFuture()->await();
        }

        return [];
    };

    $first = async(fn () => joinRoom($joiner, 1));

    drainLoop();

    // Held for the first subscribe, and owed to nobody once the socket left.
    broadcastToRoom('stale');

    $this->handler->handle($joiner, 'pusher:unsubscribe', ['channel' => 'presence-room']);

    joinRoom($joiner, 1);

    expect(confirmationsOf($joiner))->toHaveCount(1)
        ->and($recorder->hooks)->toBe(['onSubscribe presence-room']);

    broadcastToRoom('fresh');

    $gate->complete();

    $first->await();

    $channel = channels()->find('presence-room');

    expect($joiner->messages)->toBe([
        internalFrame('subscription_succeeded', $channel->data()),
        roomFrame('live', 'fresh'),
    ])
        ->and($recorder->hooks)->toBe(['onSubscribe presence-room'])
        ->and($channel->subscribed($joiner))->toBeTrue()
        ->and($joiner->hasState(EventHandler::SUBSCRIBING))->toBeFalse();

    if ($present !== null) {
        expect($present->messages)->toContain(roomFrame('live', 'stale'), roomFrame('live', 'fresh'));
    }

    $this->handler->handle($joiner, 'pusher:unsubscribe', ['channel' => 'presence-room']);

    expect($recorder->hooks)->toBe([
        'onSubscribe presence-room',
        'onUnsubscribe presence-room',
    ])
        ->and(confirmationsOf($joiner))->toHaveCount(1)
        ->and($joiner->hasState(EventHandler::SUBSCRIBING))->toBeFalse();
})->with([
    'channel kept alive by another member' => true,
    'channel emptied and recreated' => false,
]);

it('tells the plugins of an unsubscribe only after a suspended onSubscribe returned', function () {
    $gate = new DeferredFuture;

    $slow = lifecyclePlugin(function () use ($gate, &$slow) {
        $gate->getFuture()->await();

        $slow->hooks[] = 'onSubscribe returned';
    });

    $later = lifecyclePlugin();

    $joiner = new FakeConnection;

    $join = async(fn () => joinRoom($joiner, 1));

    drainLoop();

    $leave = async(fn () => $this->handler->handle($joiner, 'pusher:unsubscribe', ['channel' => 'presence-room']));

    drainLoop();

    // The unsubscribe waits for the onSubscribe pass: nothing has left yet.
    expect($leave->isComplete())->toBeFalse()
        ->and($slow->hooks)->toBe(['onSubscribe presence-room'])
        ->and($later->hooks)->toBe([])
        ->and(channels()->find('presence-room')->subscribed($joiner))->toBeTrue();

    $gate->complete();

    await([$join, $leave]);

    expect($slow->hooks)->toBe([
        'onSubscribe presence-room',
        'onSubscribe returned',
        'onUnsubscribe presence-room',
    ])
        ->and($later->hooks)->toBe([
            'onSubscribe presence-room',
            'onUnsubscribe presence-room',
        ])
        ->and(channels()->find('presence-room'))->toBeNull()
        ->and($joiner->hasState(EventHandler::SUBSCRIBING))->toBeFalse();
});

it('lets a plugin unsubscribe the connection from inside its own onSubscribe', function () {
    $plugin = lifecyclePlugin(fn (Connection $connection, Channel $channel) => test()->handler->handle(
        $connection,
        'pusher:unsubscribe',
        ['channel' => $channel->name()],
    ));

    $joiner = new FakeConnection;

    $join = async(fn () => joinRoom($joiner, 1));

    drainLoop();

    // Waiting on its own onSubscribe pass would never return.
    expect($join->isComplete())->toBeTrue();

    $join->await();

    expect($plugin->hooks)->toBe([
        'onSubscribe presence-room',
        'onUnsubscribe presence-room',
    ])
        ->and(confirmationsOf($joiner))->toHaveCount(1)
        ->and(channels()->find('presence-room'))->toBeNull()
        ->and($joiner->hasState(EventHandler::SUBSCRIBING))->toBeFalse();
});

it('terminates a joiner whose held broadcasts outgrow the outbound queue and flushes none of them', function () {
    config(['reverb.servers.reverb.max_outbound_queue_size' => 2]);

    $present = joinRoom(new FakeConnection, 2, 'Present');

    $gate = new DeferredFuture;

    lifecyclePlugin(fn () => $gate->getFuture()->await());

    $joiner = new FakeConnection;

    $join = async(fn () => joinRoom($joiner, 1));

    drainLoop();

    broadcastToRoom('one');
    broadcastToRoom('two');

    expect($joiner->wasTerminated)->toBeFalse();

    broadcastToRoom('three');

    expect($joiner->wasTerminated)->toBeTrue();

    broadcastToRoom('four');

    $gate->complete();

    $join->await();

    expect($joiner->messages)->toHaveCount(1)
        ->and(confirmationsOf($joiner))->toHaveCount(1)
        ->and($joiner->hasState(EventHandler::SUBSCRIBING))->toBeFalse()
        ->and(array_slice($present->messages, -4))->toBe([
            roomFrame('live', 'one'),
            roomFrame('live', 'two'),
            roomFrame('live', 'three'),
            roomFrame('live', 'four'),
        ]);
});

it('confirms only the resubscribe of a socket a plugin removed while its first subscribe was in flight', function (bool $keptAlive) {
    $present = $keptAlive ? joinRoom(new FakeConnection, 2, 'Present') : null;

    $recorder = lifecyclePlugin();

    $joiner = new FakeConnection;
    $gate = new DeferredFuture;
    $waited = false;

    // The first subscribe's question to the fleet stays out until the gate opens.
    $this->fleet = function (Application $app, string $type) use ($gate, &$waited) {
        if ($type === 'presence_connections' && ! $waited) {
            $waited = true;

            $gate->getFuture()->await();
        }

        return [];
    };

    $first = async(fn () => joinRoom($joiner, 1));

    drainLoop();

    // Held for the first subscribe, and owed to nobody once the plugin removed the socket.
    broadcastToRoom('stale');

    app(PluginContext::class)->unsubscribe($joiner, 'presence-room');

    joinRoom($joiner, 1);

    expect(confirmationsOf($joiner))->toHaveCount(1)
        ->and($recorder->hooks)->toBe(['onSubscribe presence-room']);

    broadcastToRoom('fresh');

    $gate->complete();

    $first->await();

    $channel = channels()->find('presence-room');

    expect($joiner->messages)->toBe([
        internalFrame('subscription_succeeded', $channel->data()),
        roomFrame('live', 'fresh'),
    ])
        ->and($recorder->hooks)->toBe(['onSubscribe presence-room'])
        ->and($channel->subscribed($joiner))->toBeTrue()
        ->and($joiner->hasState(EventHandler::SUBSCRIBING))->toBeFalse();

    if ($present !== null) {
        expect($present->messages)->toContain(roomFrame('live', 'stale'), roomFrame('live', 'fresh'));
    }

    $this->handler->handle($joiner, 'pusher:unsubscribe', ['channel' => 'presence-room']);

    expect($recorder->hooks)->toBe([
        'onSubscribe presence-room',
        'onUnsubscribe presence-room',
    ])
        ->and(confirmationsOf($joiner))->toHaveCount(1)
        ->and($joiner->hasState(EventHandler::SUBSCRIBING))->toBeFalse();
})->with([
    'channel kept alive by another member' => true,
    'channel emptied and recreated' => false,
]);

it('drops a subscribe a plugin removed mid-flight without confirming it or telling the plugins', function () {
    joinRoom(new FakeConnection, 2, 'Present');

    $this->bus->published = [];

    $recorder = lifecyclePlugin();

    $joiner = new FakeConnection;
    $gate = new DeferredFuture;
    $waited = false;

    $this->fleet = function (Application $app, string $type) use ($gate, &$waited) {
        if ($type === 'presence_connections' && ! $waited) {
            $waited = true;

            $gate->getFuture()->await();
        }

        return [];
    };

    $join = async(fn () => joinRoom($joiner, 1));

    drainLoop();

    broadcastToRoom('during');

    app(PluginContext::class)->unsubscribe($joiner, 'presence-room');

    $gate->complete();

    $join->await();

    expect($joiner->messages)->toBe([])
        ->and(confirmationsOf($joiner))->toBeEmpty()
        ->and(channels()->find('presence-room')->subscribed($joiner))->toBeFalse()
        ->and($joiner->hasState(EventHandler::SUBSCRIBING))->toBeFalse()
        ->and($recorder->hooks)->toBe([])
        ->and(busMemberEvents('member_added'))->toBeEmpty();
});

it('confirms a resubscribe issued while a plugin removal still waits on the fleet', function () {
    $joiner = joinRoom(new FakeConnection, 1);
    $joiner->messages = [];

    $recorder = lifecyclePlugin();

    $leaving = new DeferredFuture;
    $joining = new DeferredFuture;
    $asked = 0;

    // The removal asks whether the user left every node, then the
    // resubscribe asks whether it is the user's first connection.
    $this->fleet = function (Application $app, string $type) use ($leaving, $joining, &$asked) {
        if ($type === 'presence_connections') {
            match (++$asked) {
                1 => $leaving->getFuture()->await(),
                2 => $joining->getFuture()->await(),
                default => null,
            };
        }

        return [];
    };

    $removal = async(fn () => app(PluginContext::class)->unsubscribe($joiner, 'presence-room'));

    drainLoop();

    $rejoin = async(fn () => joinRoom($joiner, 1));

    drainLoop();

    // The removal finishes while the resubscribe is still in flight.
    $leaving->complete();

    $removal->await();

    $joining->complete();

    $rejoin->await();

    expect(confirmationsOf($joiner))->toHaveCount(1)
        ->and($recorder->hooks)->toBe(['onSubscribe presence-room'])
        ->and(channels()->find('presence-room')->subscribed($joiner))->toBeTrue()
        ->and($joiner->hasState(EventHandler::SUBSCRIBING))->toBeFalse();
});

it('lets a plugin remove the connection through its context from inside its own onSubscribe', function () {
    $plugin = lifecyclePlugin(function (Connection $connection, Channel $channel) {
        // Held for the subscribe in flight, and owed to nobody once the socket is removed.
        broadcastToRoom('held');

        app(PluginContext::class)->unsubscribe($connection, $channel->name());
    });

    $joiner = new FakeConnection;

    $join = async(fn () => joinRoom($joiner, 1));

    drainLoop();

    // The context never waits on the onSubscribe pass it is called from.
    expect($join->isComplete())->toBeTrue();

    $join->await();

    expect($plugin->hooks)->toBe(['onSubscribe presence-room'])
        ->and(confirmationsOf($joiner))->toHaveCount(1)
        ->and($joiner->messages)->toHaveCount(1)
        ->and(channels()->find('presence-room'))->toBeNull()
        ->and($joiner->hasState(EventHandler::SUBSCRIBING))->toBeFalse();
});

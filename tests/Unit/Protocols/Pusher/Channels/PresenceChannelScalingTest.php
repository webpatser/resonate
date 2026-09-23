<?php

use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\Fakes\FakePubSubBus;
use Webpatser\Resonate\Tests\Fakes\ScaledServerProvider;

/*
 * Presence member events on a scaled fleet: announced through the pub/sub
 * bus, and only for a user's first connection (member_added) or once the
 * last one has gone (member_removed), judged by what every node reports.
 */

beforeEach(function () {
    $this->bus = new FakePubSubBus;

    $this->app->instance(PubSubProvider::class, $this->bus);
    $this->app->instance(ServerProvider::class, new ScaledServerProvider);

    // What the fleet reports for `presence_connections`, set per test.
    $this->fleet = fn () => [];

    $this->metrics = Mockery::mock(MetricsHandler::class);
    $this->metrics->shouldReceive('gather')->andReturnUsing(fn (...$arguments) => ($this->fleet)(...$arguments));
    $this->app->instance(MetricsHandler::class, $this->metrics);
});

/**
 * Subscribe a new connection for the given user to the presence channel.
 */
function joinPresence(int $userId, ?FakeConnection $connection = null): FakeConnection
{
    $connection ??= new FakeConnection;
    $data = json_encode(['user_id' => $userId, 'user_info' => ['name' => 'Joe']]);

    channels()->findOrCreate('presence-test-channel')->subscribe(
        $connection,
        validAuth($connection->id(), 'presence-test-channel', $data),
        $data,
    );

    return $connection;
}

/**
 * Get the member events published on the bus.
 *
 * @return array<int, array<string, mixed>>
 */
function publishedMemberEvents(string $event): array
{
    return array_values(array_filter(
        test()->bus->published,
        fn (array $envelope) => ($envelope['type'] ?? null) === 'message'
            && ($envelope['payload']['event'] ?? null) === "pusher_internal:{$event}",
    ));
}

it('publishes member_added for the first connection of a user', function () {
    $joining = new FakeConnection;

    $this->fleet = fn () => [['id' => $joining->id(), 'subscribed_at' => 1.0]];

    joinPresence(1, $joining);

    $events = publishedMemberEvents('member_added');

    expect($events)->toHaveCount(1)
        ->and($events[0]['socket_id'])->toBe($joining->id())
        ->and($events[0]['application'])->toBe($joining->app()->id())
        ->and($events[0]['payload'])->toBe([
            'event' => 'pusher_internal:member_added',
            'data' => json_encode(['user_id' => 1, 'user_info' => ['name' => 'Joe']]),
            'channel' => 'presence-test-channel',
        ]);
});

it('asks the fleet for the connections of the joining user', function () {
    $this->metrics = Mockery::mock(MetricsHandler::class);
    $this->metrics->shouldReceive('gather')
        ->once()
        ->with(Mockery::any(), 'presence_connections', ['channel' => 'presence-test-channel', 'user_id' => '1'])
        ->andReturn([]);
    $this->app->instance(MetricsHandler::class, $this->metrics);

    joinPresence(1);

    expect(publishedMemberEvents('member_added'))->toHaveCount(1);
});

it('does not publish member_added when an earlier connection exists on another node', function () {
    $joining = new FakeConnection;

    $this->fleet = fn () => [
        ['id' => $joining->id(), 'subscribed_at' => 2.0],
        ['id' => 'elsewhere', 'subscribed_at' => 1.0],
    ];

    joinPresence(1, $joining);

    expect(publishedMemberEvents('member_added'))->toBeEmpty();
});

it('breaks a subscribed_at tie on the socket id', function () {
    $joining = new FakeConnection;

    // Socket ids are "<digits>.<digits>", so "0.1" sorts before and "z" after.
    $this->fleet = fn () => [
        ['id' => $joining->id(), 'subscribed_at' => 1.0],
        ['id' => '0.1', 'subscribed_at' => 1.0],
    ];

    joinPresence(1, $joining);

    expect(publishedMemberEvents('member_added'))->toBeEmpty();

    $other = new FakeConnection;

    $this->fleet = fn () => [
        ['id' => 'z', 'subscribed_at' => 1.0],
        ['id' => $other->id(), 'subscribed_at' => 1.0],
    ];

    joinPresence(2, $other);

    expect(publishedMemberEvents('member_added'))->toHaveCount(1);
});

it('publishes member_added when the fleet reports no connections', function () {
    $this->fleet = fn () => [];

    joinPresence(1);

    expect(publishedMemberEvents('member_added'))->toHaveCount(1);
});

it('publishes member_added when gathering the connections fails', function () {
    $this->fleet = fn () => throw new RuntimeException('Pub/sub unavailable.');

    joinPresence(1);

    expect(publishedMemberEvents('member_added'))->toHaveCount(1);
});

it('ignores malformed entries in the fleet report', function () {
    $joining = new FakeConnection;

    $this->fleet = fn () => [
        'not-an-entry',
        ['id' => 42, 'subscribed_at' => 0.5],
        ['subscribed_at' => 0.5],
        ['id' => $joining->id(), 'subscribed_at' => 1.0],
    ];

    joinPresence(1, $joining);

    expect(publishedMemberEvents('member_added'))->toHaveCount(1);
});

it('does not ask the fleet when the user already has a connection on this node', function () {
    joinPresence(1);

    $this->bus->published = [];

    // A gather would fail and so announce anyway, which the bus would show.
    $this->fleet = fn () => throw new LogicException('The fleet should not be asked.');

    joinPresence(1);

    expect($this->bus->published)->toBeEmpty();
});

it('does not publish member_removed while the user has connections on another node', function () {
    $leaving = joinPresence(1);

    $this->fleet = fn () => [['id' => 'elsewhere', 'subscribed_at' => 1.0]];

    channels()->find('presence-test-channel')->unsubscribe($leaving);

    expect(publishedMemberEvents('member_removed'))->toBeEmpty();
});

it('publishes member_removed when the last connection of a user leaves', function () {
    $leaving = joinPresence(1);

    $this->fleet = fn () => [];

    channels()->find('presence-test-channel')->unsubscribe($leaving);

    $events = publishedMemberEvents('member_removed');

    expect($events)->toHaveCount(1)
        ->and($events[0]['socket_id'])->toBe($leaving->id())
        ->and($events[0]['payload'])->toBe([
            'event' => 'pusher_internal:member_removed',
            'data' => json_encode(['user_id' => 1]),
            'channel' => 'presence-test-channel',
        ]);
});

it('publishes member_removed when gathering the connections fails', function () {
    $leaving = joinPresence(1);

    $this->fleet = fn () => throw new RuntimeException('Pub/sub unavailable.');

    channels()->find('presence-test-channel')->unsubscribe($leaving);

    expect(publishedMemberEvents('member_removed'))->toHaveCount(1);
});

it('does not publish member_removed while the user has another connection on this node', function () {
    $leaving = joinPresence(1);
    joinPresence(1);

    // A gather would fail and so announce anyway, which the bus would show.
    $this->fleet = fn () => throw new LogicException('The fleet should not be asked.');

    channels()->find('presence-test-channel')->unsubscribe($leaving);

    expect(publishedMemberEvents('member_removed'))->toBeEmpty();
});

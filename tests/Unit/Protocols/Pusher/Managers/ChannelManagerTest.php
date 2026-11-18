<?php

use Illuminate\Support\Facades\Event;
use Webpatser\Resonate\Events\ChannelRemoved;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Tests\Fakes\CountingChannelConnectionManager;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

beforeEach(function () {
    $this->connection = new FakeConnection;
    $this->channelManager = $this->app->make(ChannelManager::class)
        ->for($this->connection->app());
    $this->channel = $this->channelManager->findOrCreate('test-channel-0');
});

it('can subscribe to a channel', function () {
    collect(factory(5))
        ->each(fn ($connection) => $this->channel->subscribe($connection->connection()));

    expect($this->channel->connections())->toHaveCount(5);
});

it('can unsubscribe from a channel', function () {
    $connections = collect(factory(5))
        ->each(fn ($connection) => $this->channel->subscribe($connection->connection()));

    $this->channel->unsubscribe($connections->first()->connection());

    expect($this->channel->connections())->toHaveCount(4);
});

it('can get all channels', function () {
    $channels = collect(['test-channel-1', 'test-channel-2', 'test-channel-3']);

    $channels->each(fn ($channel) => $this->channelManager->findOrCreate($channel)->subscribe($this->connection));

    foreach ($this->channelManager->all() as $index => $channel) {
        expect($channel->name())->toBe($index);
    }

    expect($this->channelManager->all())->toHaveCount(4);
});

it('can determine whether a channel exists', function () {
    $this->channelManager->findOrCreate('test-channel-1');

    expect($this->channelManager->exists('test-channel-1'))->toBeTrue();
    expect($this->channelManager->exists('test-channel-2'))->toBeFalse();
});

it('can get all connections subscribed to a channel', function () {
    $connections = collect(factory(5))
        ->each(fn ($connection) => $this->channel->subscribe($connection->connection()));

    $connections->each(fn ($connection) => expect($connection->id())
        ->toBeIn(array_keys($this->channel->connections())));
});

it('can unsubscribe a connection from all channels', function () {
    $channels = collect(['test-channel-0', 'test-channel-1', 'test-channel-2']);

    $channels->each(fn ($channel) => $this->channelManager->findOrCreate($channel)->subscribe($this->connection));

    collect($this->channelManager->all())->each(fn ($channel) => expect($channel->connections())->toHaveCount(1));

    $this->channelManager->unsubscribeFromAll($this->connection);

    collect($this->channelManager->all())->each(fn ($channel) => expect($channel->connections())->toHaveCount(0));
});

it('unsubscribes from all channels by visiting only the channels of the connection', function () {
    $this->app->bind(ChannelConnectionManager::class, fn () => new CountingChannelConnectionManager);

    $bystander = new FakeConnection;

    foreach (range(1, 100) as $i) {
        $this->channelManager->findOrCreate("busy-{$i}")->subscribe($bystander);
    }

    $this->channelManager->findOrCreate('mine-1')->subscribe($this->connection);
    $this->channelManager->findOrCreate('mine-2')->subscribe($this->connection);

    CountingChannelConnectionManager::reset();

    $this->channelManager->unsubscribeFromAll($this->connection);

    expect(CountingChannelConnectionManager::$removals)->toBe(2)
        ->and($this->channelManager->exists('mine-1'))->toBeFalse()
        ->and($this->channelManager->exists('mine-2'))->toBeFalse()
        ->and($this->channelManager->all())->toHaveCount(100);
});

it('still reaps empty channels in registry order when unsubscribing from all', function () {
    Event::fake([ChannelRemoved::class]);

    $bystander = new FakeConnection;

    $this->channelManager->findOrCreate('alpha');
    $bravo = $this->channelManager->findOrCreate('bravo');
    $this->channelManager->findOrCreate('charlie')->subscribe($this->connection);
    $bravo->subscribe($this->connection);
    $this->channelManager->findOrCreate('delta')->subscribe($bystander);
    $this->channelManager->findOrCreate('echo')->subscribe($this->connection);
    $this->channelManager->findOrCreate('echo')->subscribe($bystander);

    $this->channelManager->unsubscribeFromAll($this->connection);

    $removed = [];

    Event::assertDispatched(ChannelRemoved::class, function (ChannelRemoved $event) use (&$removed) {
        $removed[] = $event->channel->name();

        return true;
    });

    // The never-subscribed channels are reaped as the full walk did, each once,
    // in the order the channels were created rather than the order joined.
    expect($removed)->toBe(['test-channel-0', 'alpha', 'bravo', 'charlie'])
        ->and(array_keys($this->channelManager->all()))->toBe(['delta', 'echo'])
        ->and($this->channelManager->find('echo')->connections())->toHaveKey($bystander->id())
        ->and($this->channelManager->find('echo')->connections())->not->toHaveKey($this->connection->id());
});

it('forgets a left channel so a later unsubscribe from all does not visit it', function () {
    $this->app->bind(ChannelConnectionManager::class, fn () => new CountingChannelConnectionManager);

    $bystander = new FakeConnection;

    $channel = $this->channelManager->findOrCreate('left');
    $channel->subscribe($bystander);
    $channel->subscribe($this->connection);
    $channel->unsubscribe($this->connection);

    $this->channelManager->findOrCreate('kept')->subscribe($this->connection);

    CountingChannelConnectionManager::reset();

    $this->channelManager->unsubscribeFromAll($this->connection);

    expect(CountingChannelConnectionManager::$removals)->toBe(1)
        ->and($this->channelManager->exists('left'))->toBeTrue()
        ->and($this->channelManager->exists('kept'))->toBeFalse();
});

it('unsubscribes from a channel recreated under the same name', function () {
    $first = $this->channelManager->findOrCreate('recreated');
    $first->subscribe($this->connection);
    $this->channelManager->remove($first);

    $second = $this->channelManager->findOrCreate('recreated');
    $second->subscribe($this->connection);
    $second->subscribe(new FakeConnection);

    $this->channelManager->unsubscribeFromAll($this->connection);

    expect($this->channelManager->find('recreated'))->toBe($second)
        ->and($second->findById($this->connection->id()))->toBeNull();
});

it('can get the data for a connection subscribed to a channel', function () {
    collect(factory(5))->each(fn ($connection) => $this->channel->subscribe(
        $connection->connection(),
        data: json_encode(['name' => 'Joe'])
    ));

    collect($this->channel->connections())->each(function ($connection) {
        expect($connection->data())->toBe(['name' => 'Joe']);
    });
});

it('can find a connection by socket id without flattening every channel', function () {
    $connections = collect(factory(3));

    $channelOne = $this->channelManager->findOrCreate('test-channel-0');
    $channelTwo = $this->channelManager->findOrCreate('test-channel-1');

    $connections->each(function ($connection) use ($channelOne, $channelTwo) {
        $channelOne->subscribe($connection->connection());
        $channelTwo->subscribe($connection->connection());
    });

    $target = $connections->first()->connection();

    $found = $this->channelManager->findConnection($target->id());

    expect($found)->not->toBeNull();
    expect($found->id())->toBe($target->id());
});

it('returns null from findConnection when the socket id is unknown', function () {
    expect($this->channelManager->findConnection('does-not-exist'))->toBeNull();
});

it('can get all connections for all channels', function () {
    $connections = factory(12);

    $channelOne = $this->channelManager->findOrCreate('test-channel-0');
    $channelTwo = $this->channelManager->findOrCreate('test-channel-1');
    $channelThree = $this->channelManager->findOrCreate('test-channel-2');

    $connections = collect($connections)->split(3);

    $connections->first()->each(function ($connection) use ($channelOne, $channelTwo, $channelThree) {
        $channelOne->subscribe($connection->connection());
        $channelTwo->subscribe($connection->connection());
        $channelThree->subscribe($connection->connection());
    });

    $connections->get(1)->each(function ($connection) use ($channelTwo, $channelThree) {
        $channelTwo->subscribe($connection->connection());

        $channelThree->subscribe($connection->connection());
    });

    $connections->last()->each(function ($connection) use ($channelThree) {
        $channelThree->subscribe($connection->connection());
    });

    expect($channelOne->connections())->toHaveCount(4);
    expect($channelTwo->connections())->toHaveCount(8);
    expect($channelThree->connections())->toHaveCount(12);
});

it('prefers a connection which knows its subscriber over an anonymous one', function () {
    $anonymous = $this->channelManager->findOrCreate('anonymous-channel');
    $identified = $this->channelManager->findOrCreate('identified-channel');

    // The anonymous channel is subscribed to first, so a merge keyed on the
    // connection identifier alone would keep the anonymous wrapper.
    $anonymous->subscribe($this->connection);
    $identified->subscribe($this->connection, data: json_encode(['user_id' => '1']));

    $connections = $this->channelManager->connections();

    expect($connections)->toHaveCount(1)
        ->and($connections[$this->connection->id()]->data('user_id'))->toBe('1');
});

it('keeps the subscriber when the identified channel is subscribed to first', function () {
    $identified = $this->channelManager->findOrCreate('identified-channel');
    $anonymous = $this->channelManager->findOrCreate('anonymous-channel');

    $identified->subscribe($this->connection, data: json_encode(['user_id' => '1']));
    $anonymous->subscribe($this->connection);

    $connections = $this->channelManager->connections();

    expect($connections)->toHaveCount(1)
        ->and($connections[$this->connection->id()]->data('user_id'))->toBe('1');
});

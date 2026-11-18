<?php

use Webpatser\Resonate\Application;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;
use Webpatser\Resonate\Protocols\Pusher\Channels\ChannelConnection;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\Managers\ArrayChannelManager;
use Webpatser\Resonate\Protocols\Pusher\Server;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

/*
 * A host may bind a manager that wraps the in-tree one and delegates to it.
 * The join index is only kept for the in-tree manager, so a wrapped one must
 * still leave every channel when a connection closes.
 */

/**
 * A manager that delegates every call to the in-tree one.
 */
class DelegatingChannelManager implements ChannelManager
{
    public function __construct(protected ChannelManager $inner) {}

    public function app(): ?Application
    {
        return $this->inner->app();
    }

    public function for(Application $application): ChannelManager
    {
        return new self($this->inner->for($application));
    }

    public function all(): array
    {
        return $this->inner->all();
    }

    public function exists(string $channel): bool
    {
        return $this->inner->exists($channel);
    }

    public function find(string $channel): ?Channel
    {
        return $this->inner->find($channel);
    }

    public function findOrCreate(string $channel): Channel
    {
        return $this->inner->findOrCreate($channel);
    }

    /**
     * @return array<string, ChannelConnection>
     */
    public function connections(?string $channel = null): array
    {
        return $this->inner->connections($channel);
    }

    public function findConnection(string $socketId): ?ChannelConnection
    {
        return $this->inner->findConnection($socketId);
    }

    public function addConnection(Connection $connection): void
    {
        $this->inner->addConnection($connection);
    }

    public function removeConnection(Connection $connection): void
    {
        $this->inner->removeConnection($connection);
    }

    public function openConnections(): array
    {
        return $this->inner->openConnections();
    }

    public function connectionCount(): int
    {
        return $this->inner->connectionCount();
    }

    public function unsubscribeFromAll(Connection $connection): void
    {
        $this->inner->unsubscribeFromAll($connection);
    }

    public function remove(Channel $channel): void
    {
        $this->inner->remove($channel);
    }

    public function flush(): void
    {
        $this->inner->flush();
    }
}

beforeEach(function () {
    $this->app->singleton(ChannelManager::class, fn () => new DelegatingChannelManager(new ArrayChannelManager));

    $this->server = $this->app->make(Server::class);
});

/**
 * Subscribe the connection to a presence channel through the server.
 */
function joinPresenceThroughServer(Server $server, FakeConnection $connection, int $userId): void
{
    $data = (string) json_encode(['user_id' => $userId, 'user_info' => ['name' => "User {$userId}"]]);

    $server->message($connection, (string) json_encode([
        'event' => 'pusher:subscribe',
        'data' => [
            'channel' => 'presence-room',
            'auth' => validAuth($connection->id(), 'presence-room', $data),
            'channel_data' => $data,
        ],
    ]));
}

it('still leaves every channel on close through a manager that wraps the in-tree one', function () {
    $this->server->open($leaving = new FakeConnection);
    $this->server->open($staying = new FakeConnection);

    joinPresenceThroughServer($this->server, $leaving, 1);
    joinPresenceThroughServer($this->server, $staying, 2);

    $this->server->message($leaving, (string) json_encode([
        'event' => 'pusher:subscribe',
        'data' => ['channel' => 'public-news'],
    ]));

    $this->server->message($staying, (string) json_encode([
        'event' => 'pusher:subscribe',
        'data' => ['channel' => 'public-news'],
    ]));

    $staying->messages = [];

    $this->server->close($leaving);

    $memberRemoved = (string) json_encode([
        'event' => 'pusher_internal:member_removed',
        'data' => json_encode(['user_id' => 1]),
        'channel' => 'presence-room',
    ]);

    expect(channels()->find('presence-room')->findById($leaving->id()))->toBeNull()
        ->and(channels()->find('public-news')->findById($leaving->id()))->toBeNull()
        ->and($staying->messages)->toContain($memberRemoved);
});

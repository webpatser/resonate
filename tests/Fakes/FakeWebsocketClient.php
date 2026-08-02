<?php

namespace Webpatser\Resonate\Tests\Fakes;

use Fledge\Async\Cancellation;
use Fledge\Async\DeferredFuture;
use Fledge\Async\Stream\InternetAddress;
use Fledge\Async\Stream\ReadableStream;
use Fledge\Async\Stream\SocketAddress;
use Fledge\Async\Stream\TlsInfo;
use Fledge\Async\WebSocket\WebsocketClient;
use Fledge\Async\WebSocket\WebsocketCloseCode;
use Fledge\Async\WebSocket\WebsocketClosedException;
use Fledge\Async\WebSocket\WebsocketCloseInfo;
use Fledge\Async\WebSocket\WebsocketCount;
use Fledge\Async\WebSocket\WebsocketMessage;
use Fledge\Async\WebSocket\WebsocketTimestamp;
use Traversable;

/**
 * A websocket client whose writes can be suspended until the test releases them.
 *
 * This is the peer that stops reading: `sendText()` suspends exactly the way
 * the real transport does when the socket refuses more bytes, so a test can
 * hold a connection's writer fiber mid-write and watch what the rest of the
 * server does in the meantime.
 */
class FakeWebsocketClient implements \IteratorAggregate, WebsocketClient
{
    /**
     * The text payloads written to the client, in the order they were written.
     *
     * @var list<string>
     */
    public array $sent = [];

    /**
     * Every frame written to the client, pings included, in write order.
     *
     * @var list<string>
     */
    public array $writes = [];

    /**
     * The marker recorded in {@see $writes} for a ping frame.
     */
    public const PING = '<ping>';

    /**
     * The number of ping frames written to the client.
     */
    public int $pings = 0;

    /**
     * The close code and reason the client was closed with, if it was.
     *
     * @var array{code: int, reason: string}|null
     */
    public ?array $closedWith = null;

    /**
     * The number of writes currently suspended.
     */
    public int $blockedWrites = 0;

    /**
     * Whether writes should suspend until released.
     */
    protected bool $blocking = false;

    /**
     * Whether writes should fail rather than succeed.
     */
    protected bool $failing = false;

    /**
     * The futures suspended writes are waiting on.
     *
     * @var list<DeferredFuture>
     */
    protected array $waiting = [];

    /**
     * Create a new fake websocket client.
     */
    public function __construct(protected int $id = 1)
    {
        //
    }

    /**
     * Suspend every subsequent write until release() is called.
     */
    public function block(): static
    {
        $this->blocking = true;

        return $this;
    }

    /**
     * Release all suspended writes and stop suspending new ones.
     */
    public function release(): static
    {
        $this->blocking = false;

        $waiting = $this->waiting;
        $this->waiting = [];

        foreach ($waiting as $deferred) {
            $deferred->complete();
        }

        return $this;
    }

    /**
     * Make every subsequent write fail the way a dropped peer does.
     */
    public function fail(): static
    {
        $this->failing = true;

        return $this;
    }

    public function sendText(string $data): void
    {
        $this->await();

        if ($this->failing) {
            throw new WebsocketClosedException(
                'Client unexpectedly closed',
                WebsocketCloseCode::ABNORMAL_CLOSE,
                'Writing to the client failed',
            );
        }

        $this->sent[] = $data;
        $this->writes[] = $data;
    }

    public function sendBinary(string $data): void
    {
        $this->sendText($data);
    }

    public function ping(): void
    {
        $this->await();

        $this->pings++;
        $this->writes[] = self::PING;
    }

    public function close(int $code = WebsocketCloseCode::NORMAL_CLOSE, string $reason = ''): void
    {
        if ($this->closedWith !== null) {
            return;
        }

        $this->closedWith = ['code' => $code, 'reason' => $reason];

        // The real transport closes the socket underneath a stalled write, so
        // releasing here is what a hard close does to a suspended writer.
        $this->failing = true;

        $this->release();
    }

    public function isClosed(): bool
    {
        return $this->closedWith !== null;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator([]);
    }

    public function receive(?Cancellation $cancellation = null): ?WebsocketMessage
    {
        return null;
    }

    public function getLocalAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 8080);
    }

    public function getRemoteAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 54321);
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return null;
    }

    public function getCloseInfo(): WebsocketCloseInfo
    {
        return new WebsocketCloseInfo(
            $this->closedWith['code'] ?? WebsocketCloseCode::NONE,
            $this->closedWith['reason'] ?? '',
            microtime(true),
            false,
        );
    }

    public function isCompressionEnabled(): bool
    {
        return false;
    }

    public function streamText(ReadableStream $stream): void
    {
        $this->sendText((string) $stream->read());
    }

    public function streamBinary(ReadableStream $stream): void
    {
        $this->streamText($stream);
    }

    public function getCount(WebsocketCount $type): int
    {
        return 0;
    }

    public function getTimestamp(WebsocketTimestamp $type): float
    {
        return microtime(true);
    }

    public function onClose(\Closure $onClose): void
    {
        //
    }

    /**
     * Suspend the calling fiber while the client is blocking writes.
     */
    protected function await(): void
    {
        if (! $this->blocking) {
            return;
        }

        $this->waiting[] = $deferred = new DeferredFuture;

        $this->blockedWrites++;

        try {
            $deferred->getFuture()->await();
        } finally {
            $this->blockedWrites--;
        }
    }
}

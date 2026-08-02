<?php

namespace Webpatser\Resonate\Server;

use Fledge\Async\Stream\InternetAddress;
use Fledge\Async\WebSocket\WebsocketClient;
use Fledge\Async\WebSocket\WebsocketCloseCode;
use Throwable;
use Webpatser\Resonate\Concurrency\SerialQueue;
use Webpatser\Resonate\Contracts\WebSocketConnection;
use Webpatser\Resonate\Loggers\Log;

use function Fledge\Async\async;

/**
 * Raw transport-level connection.
 *
 * Adapts a fledge-fiber {@see WebsocketClient} to the Resonate
 * {@see WebSocketConnection} contract. This mirrors how Reverb separates the
 * raw socket connection from the protocol-level connection, but the underlying
 * transport here is fledge-fiber rather than ReactPHP/Ratchet.
 *
 * Every outbound frame goes through a bounded per-connection queue drained by a
 * single writer fiber. `WebsocketClient::sendText()` awaits until the socket
 * accepts the bytes, so before the queue existed a peer that stopped reading
 * (TCP zero window) suspended whichever fiber happened to be writing to it, and
 * that fiber was usually a channel fan-out walking its subscriber list. One
 * client that opened a socket and never read stalled the whole channel, and the
 * broadcast behind it, for free. Enqueueing instead means the fan-out returns
 * promptly and only the stalled connection's own writer waits.
 *
 * This is the seam every write path funnels through: channel broadcasts,
 * `pusher:*` replies from `EventHandler`, error frames from `Server::error()`,
 * pings from `PingInactiveConnections`, and the close itself. Putting the queue
 * at the transport rather than in `Channel` is what makes one queue cover all
 * of them, and puts it where the socket, its closed state and its lifetime
 * already live.
 */
class RawConnection implements WebSocketConnection
{
    /**
     * The default number of messages allowed to queue for one connection.
     */
    public const DEFAULT_MAX_QUEUE_SIZE = 1_000;

    /**
     * The pending outbound frames and the fiber writing them.
     */
    protected SerialQueue $outbound;

    /**
     * Create a new raw connection instance.
     *
     * @param  int  $maxQueueSize  Frames allowed to queue before the peer is dropped. Zero disables the bound.
     */
    public function __construct(
        protected WebsocketClient $client,
        protected int $maxQueueSize = self::DEFAULT_MAX_QUEUE_SIZE,
    ) {
        $this->outbound = new SerialQueue(
            maxSize: $this->maxQueueSize,
            onOverflow: fn () => $this->dropBehindPeer(),
            onError: fn (Throwable $e) => $this->reportWriteFailure($e),
        );
    }

    /**
     * Get the underlying fledge-fiber websocket client.
     */
    public function client(): WebsocketClient
    {
        return $this->client;
    }

    /**
     * Get the raw socket connection identifier.
     */
    public function id(): int|string
    {
        return $this->client->getId();
    }

    /**
     * Get the outbound queue for this connection.
     */
    public function outbound(): SerialQueue
    {
        return $this->outbound;
    }

    /**
     * Get the remote address of the connected client.
     *
     * Internet peers report the bare IP without the port, so every connection
     * from one client keys to the same value. Unix socket peers have no IP, so
     * they fall back to the address string.
     */
    public function remoteAddress(): ?string
    {
        $address = $this->client->getRemoteAddress();

        return $address instanceof InternetAddress
            ? $address->getAddress()
            : $address->toString();
    }

    /**
     * Queue a message for the connection.
     *
     * Returns as soon as the frame is queued. Delivery order is the order of
     * these calls, whatever the socket does in between.
     */
    public function send(mixed $message): void
    {
        if ($this->client->isClosed()) {
            return;
        }

        $payload = (string) $message;

        $this->outbound->push(fn () => $this->client->sendText($payload));
    }

    /**
     * Queue a low-level ping control frame for the connection.
     *
     * Queued rather than written directly so a ping cannot jump ahead of
     * messages already waiting, and so the ping sweep is not itself a place
     * where a stalled peer can suspend the scheduler's fiber.
     */
    public function ping(): void
    {
        if ($this->client->isClosed()) {
            return;
        }

        $this->outbound->push(fn () => $this->client->ping());
    }

    /**
     * Close the connection once everything already queued has been written.
     *
     * Ordered rather than immediate because rejections send an error frame and
     * then terminate: closing out of band would race the frame off the socket.
     * A peer too stalled to drain its queue is closed by the overflow policy
     * instead, and the transport's own close period bounds the wait either way.
     */
    public function close(mixed $message = null): void
    {
        if ($this->client->isClosed()) {
            $this->outbound->discard();

            return;
        }

        $reason = $message !== null ? (string) $message : '';

        $this->outbound->finish(
            fn () => $this->client->close(WebsocketCloseCode::NORMAL_CLOSE, $reason)
        );
    }

    /**
     * Determine whether the connection has been closed.
     */
    public function isClosed(): bool
    {
        return $this->client->isClosed();
    }

    /**
     * Drop a peer that is not draining what has been queued for it.
     *
     * Unbounded buffering is the memory exhaustion vector this queue would
     * otherwise introduce, so the bound is enforced by disconnecting: a client
     * that cannot keep up with its own subscriptions has already missed the
     * ordered stream, and a Pusher client reconnects and resubscribes on its
     * own. Close code 1013 (TRY_AGAIN_LATER) says exactly that, unlike 1008
     * which would blame the client for a policy breach it did not commit.
     *
     * The queued frames are dropped first, so the fan-out fiber that tripped
     * the bound is not left pushing into a queue nobody will drain.
     */
    protected function dropBehindPeer(): void
    {
        $this->outbound->discard();

        Log::error(
            'Connection '.$this->id().' fell behind after '.$this->maxQueueSize.
            ' queued messages and was closed.'
        );

        $this->closeImmediately(WebsocketCloseCode::TRY_AGAIN_LATER, 'Outbound queue limit exceeded');
    }

    /**
     * Report a failed write and make sure the socket is not left half alive.
     *
     * Routed the way `Channel::sendTo()` routes a failed send, so a peer that
     * dies mid-write is logged rather than swallowed. A closed client is the
     * ordinary disconnect race and is logged at info level to keep it out of
     * the error stream.
     */
    protected function reportWriteFailure(Throwable $e): void
    {
        $this->outbound->discard();

        $message = 'Failed to write to connection '.$this->id().': '.$e->getMessage();

        $this->client->isClosed()
            ? Log::info('Connection Write Aborted', $message)
            : Log::error($message);

        $this->closeImmediately(WebsocketCloseCode::ABNORMAL_CLOSE, 'Write failed');
    }

    /**
     * Close the socket without waiting for the queue.
     *
     * In its own fiber because `WebsocketClient::close()` writes a close frame
     * and then waits for the peer's answer for up to the transport's close
     * period. Called inline it would suspend whichever fiber tripped the
     * overflow, which is the broadcaster, reintroducing the stall this queue
     * exists to remove. The socket is closed for reading and writing straight
     * away regardless, which is what unblocks a writer parked on it.
     */
    protected function closeImmediately(int $code, string $reason): void
    {
        if ($this->client->isClosed()) {
            return;
        }

        async(function () use ($code, $reason): void {
            try {
                $this->client->close($code, $reason);
            } catch (Throwable $e) {
                Log::error('Failed to close connection '.$this->id().': '.$e->getMessage());
            }
        })->ignore();
    }
}

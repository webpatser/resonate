<?php

namespace Webpatser\Resonate\Concurrency;

use Closure;
use Fledge\Async\Future;
use Throwable;

use function Fledge\Async\async;

/**
 * A bounded FIFO of work items drained by exactly one fiber at a time.
 *
 * This is the decoupling primitive behind both the per-connection outbound
 * queue and the pub/sub pump. Producers call {@see push()}, which appends and
 * returns immediately; a single worker fiber runs the items in the order they
 * were pushed. So work queued against one queue can never be reordered, and
 * work queued against a *different* queue is never held up by it.
 *
 * That combination is the point. Wrapping each write in its own `async()` would
 * also stop a slow peer blocking the caller, but concurrent fibers writing to
 * one socket interleave, and the Pusher protocol requires per-connection
 * ordering: a client that sees `member_removed` before the matching
 * `member_added` is a worse bug than the stall being fixed.
 *
 * The worker fiber exists only while there is work. It is started on the first
 * push and ends as soon as the queue drains, so an idle connection holds no
 * fiber, nothing keeps the event loop referenced, and an abrupt disconnect
 * cannot strand a fiber parked on a wait that will never be signalled.
 *
 * The queue is deliberately policy-free: it reports a full queue through
 * `$onOverflow` and a failed item through `$onError` and lets the owner decide.
 * A connection responds to overflow by dropping the peer; the pub/sub pump
 * responds by logging and moving on. Neither callback is optional in effect:
 * failures are always surfaced, never swallowed.
 */
class SerialQueue
{
    /**
     * The work items waiting to run, oldest first.
     *
     * The item the worker is currently running has already been shifted off, so
     * this never counts it, and {@see size()} is "queued but not yet started".
     *
     * @var list<Closure(): void>
     */
    protected array $pending = [];

    /**
     * Whether the queue still takes new work.
     */
    protected bool $accepting = true;

    /**
     * The fiber draining the queue, or null when the queue is idle.
     *
     * @var Future<void>|null
     */
    protected ?Future $worker = null;

    /**
     * Create a new serial queue.
     *
     * @param  int  $maxSize  Items allowed to wait before a push overflows. Zero disables the bound.
     * @param  (Closure(): void)|null  $onOverflow  Invoked instead of queueing when the queue is full.
     * @param  (Closure(Throwable): void)|null  $onError  Invoked when a work item throws.
     */
    public function __construct(
        protected int $maxSize,
        protected ?Closure $onOverflow = null,
        protected ?Closure $onError = null,
    ) {
        //
    }

    /**
     * Queue work behind everything already queued.
     *
     * Returns false when the work was refused, either because the queue is
     * closed to new work or because the bound was reached.
     */
    public function push(Closure $work): bool
    {
        if (! $this->accepting) {
            return false;
        }

        if ($this->maxSize > 0 && count($this->pending) >= $this->maxSize) {
            $this->report($this->onOverflow);

            return false;
        }

        $this->pending[] = $work;

        $this->startWorker();

        return true;
    }

    /**
     * Queue a final piece of work and stop accepting anything after it.
     *
     * The bound does not apply: this is the terminal item (closing a socket),
     * it is one item, and refusing it would leave the queue's owner with no way
     * to finish in order behind the writes it has already queued.
     */
    public function finish(Closure $work): bool
    {
        if (! $this->accepting) {
            return false;
        }

        $this->pending[] = $work;
        $this->accepting = false;

        $this->startWorker();

        return true;
    }

    /**
     * Drop all queued work and refuse anything further.
     *
     * Work already handed to the worker keeps running: a fiber suspended inside
     * a socket write cannot be unwound from here, and interrupting it would cut
     * a frame in half. It ends when that write returns or fails.
     */
    public function discard(): void
    {
        $this->accepting = false;
        $this->pending = [];
    }

    /**
     * Get the number of items waiting to run.
     */
    public function size(): int
    {
        return count($this->pending);
    }

    /**
     * Get the bound the queue enforces.
     */
    public function maxSize(): int
    {
        return $this->maxSize;
    }

    /**
     * Determine whether a worker fiber is currently draining the queue.
     */
    public function isDraining(): bool
    {
        return $this->worker !== null;
    }

    /**
     * Determine whether the queue still takes new work.
     */
    public function isAccepting(): bool
    {
        return $this->accepting;
    }

    /**
     * Ensure exactly one fiber is draining the queue.
     *
     * `async()` schedules the closure on the event loop rather than running it
     * inline, so the assignment below always happens before the body starts and
     * a second push during the same tick cannot start a second worker.
     */
    protected function startWorker(): void
    {
        if ($this->worker !== null) {
            return;
        }

        $this->worker = async(function (): void {
            try {
                while (($work = array_shift($this->pending)) !== null) {
                    try {
                        $work();
                    } catch (Throwable $e) {
                        $this->report($this->onError, $e);
                    }
                }
            } finally {
                $this->worker = null;
            }
        });
    }

    /**
     * Invoke an owner callback without letting it break the worker.
     *
     * @param  (Closure(Throwable): void)|(Closure(): void)|null  $callback
     */
    protected function report(?Closure $callback, ?Throwable $exception = null): void
    {
        if ($callback === null) {
            return;
        }

        try {
            $exception === null ? $callback() : $callback($exception);
        } catch (Throwable) {
            // An owner whose overflow or error handler itself fails must not
            // take the worker fiber down with it; the next item still runs.
        }
    }
}

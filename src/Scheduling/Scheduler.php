<?php

namespace Webpatser\Resonate\Scheduling;

use Closure;
use Revolt\EventLoop;
use Throwable;
use Webpatser\Resonate\Contracts\Logger;

use function Fledge\Async\async;

/**
 * A thin, resonate-owned scheduler over the Revolt event loop.
 *
 * Every periodic task the server runs - the restart poll, cyclic GC,
 * connection maintenance, Pulse/Telescope ingest, plugin ticks - is registered
 * here instead of calling {@see EventLoop::repeat()} directly. That gives each
 * task the same treatment: its body runs inside a fiber (via `async()`) so
 * async DB/Redis calls suspend rather than block the loop, every run is
 * exception-isolated so a throw can never cancel the timer or crash the loop,
 * and every task is named and cancellable.
 */
class Scheduler
{
    /**
     * The registered tasks, keyed by their Revolt callback id.
     *
     * @var array<string, array{id: string, name: string, interval: float, type: string}>
     */
    protected array $tasks = [];

    /**
     * Ids of recurring tasks whose previous run has not finished yet.
     *
     * @var array<string, true>
     */
    protected array $running = [];

    /**
     * Create a new scheduler.
     */
    public function __construct(protected Logger $logger)
    {
        //
    }

    /**
     * Register a recurring task.
     *
     * Runs are serialised per registration: while a run is still pending the
     * next tick is skipped instead of starting a second fiber. Without this a
     * task that outlives its interval (a maintenance sweep against a slow Redis,
     * say) accumulates overlapping fibers, and the overlap is not merely wasted
     * work: two sweeps walking the same connections emit duplicate events for
     * the same connection. The guard is per registration rather than per name
     * because several distinct tasks legitimately share a name (every plugin
     * tick registers as `plugin:tick`), and those must not block each other.
     *
     * Returns the Revolt callback id, which can be passed to {@see cancel()}.
     */
    public function repeat(float $interval, callable $callback, string $name): string
    {
        $guard = $this->guard($name, $callback);

        $id = EventLoop::repeat($interval, function () use (&$id, $guard, $name): void {
            if (isset($this->running[$id])) {
                $this->logger->info(
                    'Scheduler',
                    "Skipping tick for [{$name}]: the previous run has not finished yet."
                );

                return;
            }

            $this->running[$id] = true;

            // async() returns a Future; the task is fire-and-forget, and the
            // guard already captures any failure, so the Future is discarded.
            (void) async(function () use (&$id, $guard): void {
                try {
                    $guard();
                } finally {
                    unset($this->running[$id]);
                }
            });
        });

        $this->tasks[$id] = ['id' => $id, 'name' => $name, 'interval' => $interval, 'type' => 'repeat'];

        return $id;
    }

    /**
     * Determine whether a recurring task's previous run is still pending.
     */
    public function isRunning(string $id): bool
    {
        return isset($this->running[$id]);
    }

    /**
     * Register a one-shot task to run once after the given delay.
     *
     * Returns the Revolt callback id, which can be passed to {@see cancel()}.
     */
    public function delay(float $delay, callable $callback, string $name): string
    {
        $guard = $this->guard($name, $callback);

        $id = EventLoop::delay($delay, function () use (&$id, $guard): void {
            unset($this->tasks[$id]);

            (void) async($guard);
        });

        $this->tasks[$id] = ['id' => $id, 'name' => $name, 'interval' => $delay, 'type' => 'delay'];

        return $id;
    }

    /**
     * Cancel a single task by its id.
     */
    public function cancel(string $id): void
    {
        EventLoop::cancel($id);

        unset($this->tasks[$id], $this->running[$id]);
    }

    /**
     * Cancel every registered task.
     *
     * Called when the server begins shutting down (drain or stop) so no
     * periodic work fires while connections are draining.
     */
    public function cancelAll(): void
    {
        foreach (array_keys($this->tasks) as $id) {
            EventLoop::cancel($id);
        }

        $this->tasks = [];
        $this->running = [];
    }

    /**
     * Get the registered tasks.
     *
     * @return array<int, array{id: string, name: string, interval: float, type: string}>
     */
    public function tasks(): array
    {
        return array_values($this->tasks);
    }

    /**
     * Wrap a task callback so a throw is logged and isolated.
     *
     * The returned closure is what actually runs on the loop: it never lets a
     * `Throwable` escape, so a failing task can neither cancel its own timer
     * nor surface as a Revolt uncaught error.
     */
    /**
     * @return Closure(): void
     */
    protected function guard(string $name, callable $callback): Closure
    {
        return function () use ($name, $callback): void {
            try {
                $callback();
            } catch (Throwable $e) {
                $this->logger->error("Scheduled task [{$name}] failed: ".$e->getMessage());
            }
        };
    }
}

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
     * Create a new scheduler.
     */
    public function __construct(protected Logger $logger)
    {
        //
    }

    /**
     * Register a recurring task.
     *
     * Returns the Revolt callback id, which can be passed to {@see cancel()}.
     */
    public function repeat(float $interval, callable $callback, string $name): string
    {
        $guard = $this->guard($name, $callback);

        $id = EventLoop::repeat($interval, static function () use ($guard): void {
            // async() returns a Future; the task is fire-and-forget, and the
            // guard already captures any failure, so the Future is discarded.
            (void) async($guard);
        });

        $this->tasks[$id] = ['id' => $id, 'name' => $name, 'interval' => $interval, 'type' => 'repeat'];

        return $id;
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

        unset($this->tasks[$id]);
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

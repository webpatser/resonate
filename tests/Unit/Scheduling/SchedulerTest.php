<?php

use Revolt\EventLoop;
use Webpatser\Resonate\Scheduling\Scheduler;
use Webpatser\Resonate\Tests\Fakes\RecordingLogger;

beforeEach(function () {
    $this->logger = new RecordingLogger;
    $this->scheduler = new Scheduler($this->logger);
});

afterEach(function () {
    // The Revolt loop is process-global; drop any task this test left behind.
    $this->scheduler->cancelAll();
});

it('registers a recurring task and returns its id', function () {
    $id = $this->scheduler->repeat(30, fn () => null, 'memory:gc');

    expect($id)->toBeString()->not->toBeEmpty()
        ->and($this->scheduler->tasks())->toHaveCount(1);

    $task = $this->scheduler->tasks()[0];

    expect($task['id'])->toBe($id)
        ->and($task['name'])->toBe('memory:gc')
        ->and($task['interval'])->toBe(30.0)
        ->and($task['type'])->toBe('repeat');
});

it('registers a one-shot task', function () {
    $this->scheduler->delay(10, fn () => null, 'drain:watchdog');

    $task = $this->scheduler->tasks()[0];

    expect($task['name'])->toBe('drain:watchdog')
        ->and($task['interval'])->toBe(10.0)
        ->and($task['type'])->toBe('delay');
});

it('cancels a single task', function () {
    $keep = $this->scheduler->repeat(30, fn () => null, 'keep');
    $drop = $this->scheduler->repeat(30, fn () => null, 'drop');

    $this->scheduler->cancel($drop);

    expect($this->scheduler->tasks())->toHaveCount(1)
        ->and($this->scheduler->tasks()[0]['id'])->toBe($keep);
});

it('cancels every task', function () {
    $this->scheduler->repeat(30, fn () => null, 'a');
    $this->scheduler->repeat(60, fn () => null, 'b');

    $this->scheduler->cancelAll();

    expect($this->scheduler->tasks())->toBeEmpty();
});

it('isolates a throwing task and keeps the timer alive', function () {
    $this->scheduler->repeat(0.01, function (): void {
        throw new RuntimeException('boom');
    }, 'flaky');

    EventLoop::delay(0.1, static fn () => EventLoop::getDriver()->stop());
    EventLoop::run();

    expect(count($this->logger->errors))->toBeGreaterThanOrEqual(2)
        ->and($this->logger->errors[0])->toContain('Scheduled task [flaky] failed: boom');
});

/*
 * Regression: no re-entrancy guard.
 *
 * `repeat()` fired `async()` on every tick regardless of whether the previous
 * run had finished, so a task that outlives its interval (a maintenance sweep
 * against a slow Redis, say) accumulated overlapping fibers. That is not merely
 * wasted work: two sweeps walking the same connections emit duplicate events
 * for the same connection.
 */
it('skips a tick while the previous run of the same task is still pending', function () {
    $started = 0;
    $finished = 0;
    $suspension = null;

    // A task that never returns within the test window: every subsequent tick
    // must be skipped rather than starting a second run.
    $id = $this->scheduler->repeat(0.01, function () use (&$started, &$finished, &$suspension): void {
        $started++;
        $suspension = EventLoop::getSuspension();
        $suspension->suspend();
        $finished++;
    }, 'slow:sweep');

    EventLoop::delay(0.15, static fn () => EventLoop::getDriver()->stop());
    EventLoop::run();

    expect($started)->toBe(1)
        ->and($finished)->toBe(0)
        ->and($this->scheduler->isRunning($id))->toBeTrue()
        // Every skipped tick says so, so an overrunning task is visible.
        ->and(count($this->logger->info))->toBeGreaterThan(1)
        ->and($this->logger->info[0]['message'])->toContain('[slow:sweep]');

    // Let the pending run finish; the task becomes eligible again, so the next
    // tick starts a fresh run instead of staying wedged forever.
    $suspension->resume();

    EventLoop::delay(0.05, static fn () => EventLoop::getDriver()->stop());
    EventLoop::run();

    expect($finished)->toBe(1)
        ->and($started)->toBeGreaterThan(1);
});

it('releases the guard when a run throws so the task is not wedged forever', function () {
    $runs = 0;

    $id = $this->scheduler->repeat(0.01, function () use (&$runs): void {
        $runs++;

        throw new RuntimeException('boom');
    }, 'flaky');

    EventLoop::delay(0.1, static fn () => EventLoop::getDriver()->stop());
    EventLoop::run();

    expect($runs)->toBeGreaterThan(1)
        ->and($this->scheduler->isRunning($id))->toBeFalse();
});

it('guards each registration separately so tasks sharing a name do not block each other', function () {
    // Every plugin tick registers under the name `plugin:tick`; a per-name
    // guard would let one slow plugin starve all the others.
    $runs = [0, 0];
    $suspension = null;

    $this->scheduler->repeat(0.01, function () use (&$runs, &$suspension): void {
        $runs[0]++;
        $suspension = EventLoop::getSuspension();
        $suspension->suspend();
    }, 'plugin:tick');

    $this->scheduler->repeat(0.01, function () use (&$runs): void {
        $runs[1]++;
    }, 'plugin:tick');

    EventLoop::delay(0.15, static fn () => EventLoop::getDriver()->stop());
    EventLoop::run();

    expect($runs[0])->toBe(1)
        ->and($runs[1])->toBeGreaterThan(1);

    $suspension->resume();
});

it('runs a one-shot task once and then forgets it', function () {
    $runs = 0;

    $this->scheduler->delay(0.01, function () use (&$runs): void {
        $runs++;
    }, 'once');

    EventLoop::delay(0.1, static fn () => EventLoop::getDriver()->stop());
    EventLoop::run();

    expect($runs)->toBe(1)
        ->and($this->scheduler->tasks())->toBeEmpty();
});

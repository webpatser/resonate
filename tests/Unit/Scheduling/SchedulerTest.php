<?php

use Fledge\Loop;
use Time\Duration;
use Webpatser\Resonate\Scheduling\Scheduler;
use Webpatser\Resonate\Tests\Fakes\RecordingLogger;

beforeEach(function () {
    $this->logger = new RecordingLogger;
    $this->scheduler = new Scheduler($this->logger);
});

afterEach(function () {
    // The event loop is process-global; drop any task this test left behind.
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

    Loop::delay(0.1, static fn () => Loop::getDriver()->stop());
    Loop::run();

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
        $suspension = Loop::getSuspension();
        $suspension->suspend();
        $finished++;
    }, 'slow:sweep');

    Loop::delay(0.15, static fn () => Loop::getDriver()->stop());
    Loop::run();

    expect($started)->toBe(1)
        ->and($finished)->toBe(0)
        ->and($this->scheduler->isRunning($id))->toBeTrue()
        // Every skipped tick says so, so an overrunning task is visible.
        ->and(count($this->logger->info))->toBeGreaterThan(1)
        ->and($this->logger->info[0]['message'])->toContain('[slow:sweep]');

    // Let the pending run finish; the task becomes eligible again, so the next
    // tick starts a fresh run instead of staying wedged forever.
    $suspension->resume();

    Loop::delay(0.05, static fn () => Loop::getDriver()->stop());
    Loop::run();

    expect($finished)->toBe(1)
        ->and($started)->toBeGreaterThan(1);
});

it('releases the guard when a run throws so the task is not wedged forever', function () {
    $runs = 0;

    $id = $this->scheduler->repeat(0.01, function () use (&$runs): void {
        $runs++;

        throw new RuntimeException('boom');
    }, 'flaky');

    Loop::delay(0.1, static fn () => Loop::getDriver()->stop());
    Loop::run();

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
        $suspension = Loop::getSuspension();
        $suspension->suspend();
    }, 'plugin:tick');

    $this->scheduler->repeat(0.01, function () use (&$runs): void {
        $runs[1]++;
    }, 'plugin:tick');

    Loop::delay(0.15, static fn () => Loop::getDriver()->stop());
    Loop::run();

    expect($runs[0])->toBe(1)
        ->and($runs[1])->toBeGreaterThan(1);

    $suspension->resume();
});

it('runs a one-shot task once and then forgets it', function () {
    $runs = 0;

    $this->scheduler->delay(0.01, function () use (&$runs): void {
        $runs++;
    }, 'once');

    Loop::delay(0.1, static fn () => Loop::getDriver()->stop());
    Loop::run();

    expect($runs)->toBe(1)
        ->and($this->scheduler->tasks())->toBeEmpty();
});

it('stores a Duration interval and delay as the same float as seconds', function () {
    $this->scheduler->repeat(Duration::fromMilliseconds(1500), fn () => null, 'duration:repeat');
    $this->scheduler->delay(Duration::fromSeconds(10), fn () => null, 'duration:delay');
    $this->scheduler->repeat(1.5, fn () => null, 'float:repeat');
    $this->scheduler->delay(10.0, fn () => null, 'float:delay');

    [$durationRepeat, $durationDelay, $floatRepeat, $floatDelay] = $this->scheduler->tasks();

    expect($durationRepeat['interval'])->toBeFloat()->toBe(1.5)->toBe($floatRepeat['interval'])
        ->and($durationDelay['interval'])->toBeFloat()->toBe(10.0)->toBe($floatDelay['interval'])
        ->and($durationRepeat['type'])->toBe('repeat')
        ->and($durationDelay['type'])->toBe('delay');
});

it('runs a recurring task on a Duration interval', function () {
    $runs = 0;

    $this->scheduler->repeat(Duration::fromMilliseconds(10), function () use (&$runs): void {
        $runs++;
    }, 'duration:tick');

    Loop::delay(0.1, static fn () => Loop::getDriver()->stop());
    Loop::run();

    expect($runs)->toBeGreaterThan(1);
});

it('runs a one-shot task after a Duration delay and then forgets it', function () {
    $runs = 0;

    $this->scheduler->delay(Duration::fromMilliseconds(10), function () use (&$runs): void {
        $runs++;
    }, 'duration:once');

    Loop::delay(0.1, static fn () => Loop::getDriver()->stop());
    Loop::run();

    expect($runs)->toBe(1)
        ->and($this->scheduler->tasks())->toBeEmpty();
});

it('rejects a negative interval and registers nothing', function (Duration|float $interval) {
    expect(fn () => $this->scheduler->repeat($interval, fn () => null, 'negative'))
        ->toThrow(Error::class, 'Interval must be greater than or equal to zero')
        ->and($this->scheduler->tasks())->toBeEmpty();
})->with([
    'negative float' => -0.25,
    'negated Duration' => fn () => Duration::fromMilliseconds(250)->negate(),
]);

it('rejects a negative delay and registers nothing', function (Duration|float $delay) {
    expect(fn () => $this->scheduler->delay($delay, fn () => null, 'negative'))
        ->toThrow(Error::class, 'Delay must be greater than or equal to zero')
        ->and($this->scheduler->tasks())->toBeEmpty();
})->with([
    'negative float' => -0.25,
    'negated Duration' => fn () => Duration::fromMilliseconds(250)->negate(),
]);

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

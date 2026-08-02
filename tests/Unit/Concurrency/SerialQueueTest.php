<?php

use Webpatser\Resonate\Concurrency\SerialQueue;

use function Fledge\Async\async;
use function Fledge\Async\delay;

/*
 * The primitive behind the per-connection outbound queue and the pub/sub pump:
 * a bounded FIFO drained by exactly one fiber. Ordering is the whole point, so
 * every case here pushes work that suspends, which is what would let concurrent
 * fibers interleave if there were more than one worker.
 */

it('runs queued work in the order it was pushed, however long each item takes', function () {
    $queue = new SerialQueue(maxSize: 100);
    $ran = [];

    // Descending delays: with one fiber per item the fast ones would finish
    // first and the recorded order would come out reversed.
    foreach ([0.012, 0.008, 0.004, 0.0] as $index => $seconds) {
        $queue->push(function () use (&$ran, $index, $seconds) {
            delay($seconds);

            $ran[] = $index;
        });
    }

    drainLoop();

    expect($ran)->toBe([0, 1, 2, 3]);
});

it('keeps push order when the pushes themselves come from concurrent fibers', function () {
    $queue = new SerialQueue(maxSize: 100);
    $pushed = [];
    $ran = [];

    $fibers = [];

    foreach (range(1, 10) as $index) {
        $fibers[] = async(function () use ($queue, &$pushed, &$ran, $index) {
            delay(0.0005 * $index);

            $pushed[] = $index;

            $queue->push(function () use (&$ran, $index) {
                delay(0.002);

                $ran[] = $index;
            });
        });
    }

    drainLoop();

    expect($ran)->toBe($pushed)->and($ran)->toHaveCount(10);
});

it('never runs two items at once', function () {
    $queue = new SerialQueue(maxSize: 100);
    $running = 0;
    $peak = 0;

    foreach (range(1, 5) as $index) {
        $queue->push(function () use (&$running, &$peak) {
            $peak = max($peak, ++$running);

            delay(0.002);

            $running--;
        });
    }

    drainLoop();

    expect($peak)->toBe(1);
});

it('refuses work beyond the bound and reports the overflow', function () {
    $overflows = 0;
    $queue = new SerialQueue(maxSize: 2, onOverflow: function () use (&$overflows) {
        $overflows++;
    });

    // Nothing drains until the loop runs, so all three pushes see the bound.
    expect($queue->push(fn () => null))->toBeTrue()
        ->and($queue->push(fn () => null))->toBeTrue()
        ->and($queue->push(fn () => null))->toBeFalse()
        ->and($overflows)->toBe(1)
        ->and($queue->size())->toBe(2);
});

it('applies no bound when the maximum is zero', function () {
    $queue = new SerialQueue(maxSize: 0);

    foreach (range(1, 50) as $ignored) {
        expect($queue->push(fn () => null))->toBeTrue();
    }

    expect($queue->size())->toBe(50);
});

it('accepts a final item past the bound and then stops accepting', function () {
    $queue = new SerialQueue(maxSize: 1);
    $ran = [];

    $queue->push(function () use (&$ran) {
        $ran[] = 'first';
    });

    expect($queue->finish(function () use (&$ran) {
        $ran[] = 'last';
    }))->toBeTrue();

    expect($queue->isAccepting())->toBeFalse()
        ->and($queue->push(function () use (&$ran) {
            $ran[] = 'refused';
        }))->toBeFalse();

    drainLoop();

    expect($ran)->toBe(['first', 'last']);
});

it('drops everything queued when discarded', function () {
    $queue = new SerialQueue(maxSize: 10);
    $ran = [];

    $queue->push(function () use (&$ran) {
        $ran[] = 'dropped';
    });
    $queue->discard();

    drainLoop();

    expect($ran)->toBe([])
        ->and($queue->size())->toBe(0)
        ->and($queue->isAccepting())->toBeFalse();
});

it('reports a failing item and keeps running the rest', function () {
    $errors = [];
    $queue = new SerialQueue(maxSize: 10, onError: function (Throwable $e) use (&$errors) {
        $errors[] = $e->getMessage();
    });

    $ran = [];

    $queue->push(function () use (&$ran) {
        $ran[] = 'before';
    });
    $queue->push(fn () => throw new RuntimeException('write failed'));
    $queue->push(function () use (&$ran) {
        $ran[] = 'after';
    });

    drainLoop();

    expect($errors)->toBe(['write failed'])
        ->and($ran)->toBe(['before', 'after']);
});

it('lets an owner stop the queue from inside its own error handler', function () {
    $ran = [];
    $queue = null;

    $queue = new SerialQueue(maxSize: 10, onError: function () use (&$queue) {
        $queue->discard();
    });

    $queue->push(fn () => throw new RuntimeException('write failed'));
    $queue->push(function () use (&$ran) {
        $ran[] = 'never';
    });

    drainLoop();

    expect($ran)->toBe([])
        ->and($queue->isAccepting())->toBeFalse();
});

it('survives an owner callback that throws', function () {
    $queue = new SerialQueue(maxSize: 10, onError: fn () => throw new RuntimeException('handler failed'));
    $ran = [];

    $queue->push(fn () => throw new RuntimeException('write failed'));
    $queue->push(function () use (&$ran) {
        $ran[] = 'after';
    });

    drainLoop();

    expect($ran)->toBe(['after']);
});

it('holds no worker fiber once the queue drains', function () {
    $queue = new SerialQueue(maxSize: 10);

    expect($queue->isDraining())->toBeFalse();

    $queue->push(fn () => delay(0.001));

    expect($queue->isDraining())->toBeTrue();

    drainLoop();

    expect($queue->isDraining())->toBeFalse()
        ->and($queue->size())->toBe(0);

    // A queue that went idle still takes new work, on a fresh worker.
    $ran = false;
    $queue->push(function () use (&$ran) {
        $ran = true;
    });

    drainLoop();

    expect($ran)->toBeTrue();
});

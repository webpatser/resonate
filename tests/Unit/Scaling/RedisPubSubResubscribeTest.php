<?php

use Fledge\Async\Redis\RedisException;
use Fledge\Async\Redis\RedisSubscription;
use Revolt\EventLoop;
use Webpatser\Resonate\Scaling\Contracts\PubSubIncomingMessageHandler;
use Webpatser\Resonate\Scaling\RedisPubSubProvider;

/*
 * The subscriber used to die permanently on the first failure: the fiber
 * logged one line and ended, with nothing to resubscribe. Because
 * RedisSubscriber's own inline retry calls connect() outside its catch, a
 * reconnect attempted while Redis is still down (the normal case during a
 * restart) stops that subscriber terminally. The node then kept publishing but
 * never received another broadcast, terminate request or metrics reply until
 * the process was restarted.
 *
 * These tests drive the retry loop through the two seams the provider exposes
 * for it, so no live Redis is needed.
 */

final class FailingPubSubProvider extends RedisPubSubProvider
{
    public int $subscribeAttempts = 0;

    public int $reconnects = 0;

    public int $stopAfter = 3;

    protected function openSubscription(): RedisSubscription
    {
        $this->subscribeAttempts++;

        if ($this->subscribeAttempts >= $this->stopAfter) {
            $this->disconnect();
        }

        throw new RedisException('connection refused');
    }

    protected function reconnectSubscriber(): void
    {
        $this->reconnects++;
    }

    protected function retryDelay(int $failures): float
    {
        return 0.001;
    }
}

function makeFailingProvider(int $stopAfter = 3): FailingPubSubProvider
{
    $handler = Mockery::mock(PubSubIncomingMessageHandler::class);

    $provider = new FailingPubSubProvider($handler, 'resonate');
    $provider->stopAfter = $stopAfter;

    return $provider;
}

/**
 * Run the loop until it goes idle or the safety deadline expires.
 */
function runLoopUntilIdle(float $seconds = 1.0): void
{
    EventLoop::delay($seconds, fn () => EventLoop::getDriver()->stop());

    EventLoop::run();
}

it('resubscribes after a failure instead of dying on the first one', function () {
    $provider = makeFailingProvider(stopAfter: 3);

    $provider->subscribe();

    runLoopUntilIdle();

    // Before the fix this was exactly 1: one failure ended the fiber for good.
    expect($provider->subscribeAttempts)->toBe(3)
        ->and($provider->reconnects)->toBe(2);
});

it('builds a fresh subscriber on each retry rather than reusing the stopped one', function () {
    $provider = makeFailingProvider(stopAfter: 4);

    $provider->subscribe();

    runLoopUntilIdle();

    // One reconnect between each pair of attempts.
    expect($provider->reconnects)->toBe($provider->subscribeAttempts - 1);
});

it('stops retrying once disconnected', function () {
    $provider = makeFailingProvider(stopAfter: 2);

    $provider->subscribe();

    runLoopUntilIdle();

    $attemptsAtDisconnect = $provider->subscribeAttempts;

    // Give the loop more time; nothing further should be attempted.
    runLoopUntilIdle(0.2);

    expect($provider->subscribeAttempts)->toBe($attemptsAtDisconnect)
        ->and($attemptsAtDisconnect)->toBe(2);
});

it('backs off exponentially and caps the delay', function () {
    $provider = new class(Mockery::mock(PubSubIncomingMessageHandler::class), 'resonate') extends RedisPubSubProvider
    {
        public function delayFor(int $failures): float
        {
            return $this->retryDelay($failures);
        }
    };

    expect($provider->delayFor(0))->toBe(0.5)
        ->and($provider->delayFor(1))->toBe(1.0)
        ->and($provider->delayFor(2))->toBe(2.0)
        ->and($provider->delayFor(20))->toBe(10.0);
});

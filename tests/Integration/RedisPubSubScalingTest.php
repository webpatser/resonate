<?php

use Revolt\EventLoop;
use Webpatser\Resonate\Scaling\PusherPubSubIncomingMessageHandler;
use Webpatser\Resonate\Scaling\RedisPubSubProvider;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

use function Fledge\Async\delay;

/*
 * Phase 4 milestone: prove the horizontal-scaling transport end-to-end against
 * a real Redis server. A `message` envelope published through the
 * RedisPubSubProvider must travel out over Redis, come back in on the
 * subscribe fiber, route through PusherPubSubIncomingMessageHandler, and reach
 * a connection subscribed to the channel: exactly the path a broadcast takes
 * from one Resonate instance to a client on another.
 *
 * Skipped cleanly when no Redis is reachable, so CI without Redis stays green.
 */

/**
 * Determine whether a Redis server is reachable for the scaling tests.
 */
function redisReachable(): bool
{
    $connection = @fsockopen('127.0.0.1', 6379, $errno, $errstr, 0.5);

    if ($connection === false) {
        return false;
    }

    fclose($connection);

    return true;
}

it('delivers a broadcast envelope across the Redis pub/sub transport', function () {
    channels()->findOrCreate('updates')->subscribe($connection = new FakeConnection);

    $provider = new RedisPubSubProvider(
        new PusherPubSubIncomingMessageHandler,
        'resonate-test-'.uniqid(),
        ['host' => '127.0.0.1', 'port' => 6379, 'database' => 15],
    );

    $failure = null;

    $watchdog = EventLoop::delay(5.0, function () use (&$failure) {
        $failure = 'timed out waiting for the Redis round-trip';
        EventLoop::getDriver()->stop();
    });

    EventLoop::queue(function () use ($provider, &$failure, $watchdog) {
        try {
            $provider->connect();
            delay(0.2); // let the subscribe fiber establish the subscription

            $provider->publish([
                'type' => 'message',
                'application' => 'app-id',
                'payload' => [
                    'event' => 'OrderShipped',
                    'channels' => ['updates'],
                    'data' => json_encode(['id' => 1]),
                ],
            ]);

            delay(0.3); // let the envelope round-trip through Redis
        } catch (\Throwable $e) {
            $failure = $e->getMessage();
        } finally {
            EventLoop::cancel($watchdog);
            $provider->disconnect();
            EventLoop::getDriver()->stop();
        }
    });

    EventLoop::run();

    expect($failure)->toBeNull()
        ->and($connection->messages)->not->toBeEmpty()
        ->and($connection->messages[0])->toContain('OrderShipped')
        ->and($connection->messages[0])->toContain('updates');
})->skip(fn () => ! redisReachable(), 'Redis is not reachable on 127.0.0.1:6379')->group('integration');

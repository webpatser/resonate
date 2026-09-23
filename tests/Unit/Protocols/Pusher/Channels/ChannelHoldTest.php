<?php

use Webpatser\Resonate\Tests\Fakes\FakeConnection;

/*
 * A held connection has its broadcasts buffered instead of sent, so a
 * subscribe that suspends between the join and its confirmation cannot be
 * overtaken. The last release delivers the buffer in arrival order.
 */

beforeEach(function () {
    $this->channel = channels()->findOrCreate('test-channel');

    $this->held = new FakeConnection;
    $this->other = new FakeConnection;

    $this->channel->subscribe($this->held);
    $this->channel->subscribe($this->other);
});

/**
 * Format a broadcast on the test channel.
 */
function heldFrame(string $data): string
{
    return (string) json_encode(['event' => 'live', 'channel' => 'test-channel', 'data' => $data]);
}

it('buffers broadcasts to a held connection and delivers them in arrival order on release', function () {
    $this->channel->hold($this->held);

    $this->channel->broadcast(['event' => 'live', 'channel' => 'test-channel', 'data' => 'first']);
    $this->channel->broadcast(['event' => 'live', 'channel' => 'test-channel', 'data' => 'second']);

    expect($this->held->messages)->toBe([]);

    $this->channel->release($this->held);

    expect($this->held->messages)->toBe([heldFrame('first'), heldFrame('second')]);
});

it('sends to connections that are not held straight away', function () {
    $this->channel->hold($this->held);

    $this->channel->broadcast(['event' => 'live', 'channel' => 'test-channel', 'data' => 'first']);

    expect($this->other->messages)->toBe([heldFrame('first')]);

    $this->channel->release($this->held);

    expect($this->other->messages)->toBe([heldFrame('first')]);
});

it('delivers only when the last of nested holds is released', function () {
    $this->channel->hold($this->held);
    $this->channel->hold($this->held);

    $this->channel->broadcast(['event' => 'live', 'channel' => 'test-channel', 'data' => 'first']);

    $this->channel->release($this->held);

    $this->channel->broadcast(['event' => 'live', 'channel' => 'test-channel', 'data' => 'second']);

    expect($this->held->messages)->toBe([]);

    $this->channel->release($this->held);

    expect($this->held->messages)->toBe([heldFrame('first'), heldFrame('second')]);

    $this->channel->broadcast(['event' => 'live', 'channel' => 'test-channel', 'data' => 'third']);

    expect($this->held->messages)->toBe([heldFrame('first'), heldFrame('second'), heldFrame('third')]);
});

it('drops the buffer when the connection left the channel before the release', function () {
    $this->channel->hold($this->held);

    $this->channel->broadcast(['event' => 'live', 'channel' => 'test-channel', 'data' => 'missed']);

    $this->channel->unsubscribe($this->held);
    $this->channel->release($this->held);

    expect($this->held->messages)->toBe([]);

    // Back in the channel, it receives only what is broadcast from now on.
    $this->channel->subscribe($this->held);

    $this->channel->broadcast(['event' => 'live', 'channel' => 'test-channel', 'data' => 'fresh']);

    expect($this->held->messages)->toBe([heldFrame('fresh')]);
});

it('buffers a broadcast that excludes another connection', function () {
    $this->channel->hold($this->held);

    $this->channel->broadcast(['event' => 'live', 'channel' => 'test-channel', 'data' => 'first'], $this->other);

    expect($this->held->messages)->toBe([])
        ->and($this->other->messages)->toBe([]);

    $this->channel->release($this->held);

    expect($this->held->messages)->toBe([heldFrame('first')]);
});

it('ignores a release without a hold', function () {
    $this->channel->release($this->held);

    $this->channel->broadcast(['event' => 'live', 'channel' => 'test-channel', 'data' => 'first']);

    expect($this->held->messages)->toBe([heldFrame('first')]);
});

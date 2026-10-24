<?php

use Webpatser\Resonate\Contracts\Logger;
use Webpatser\Resonate\Loggers\Log;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;
use Webpatser\Resonate\Protocols\Pusher\Channels\ChannelConnection;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;
use Webpatser\Resonate\Tests\Fakes\RecordingLogger;

function loggingChannel(string $name): Channel
{
    $manager = Mockery::mock(ChannelConnectionManager::class);
    $manager->shouldReceive('for')->andReturnSelf();
    $manager->shouldReceive('all')->andReturn(['a' => new ChannelConnection(new FakeConnection)]);
    app()->instance(ChannelConnectionManager::class, $manager);

    return new Channel($name);
}

beforeEach(function () {
    $this->logger = new RecordingLogger;
    $this->app->instance(Logger::class, $this->logger);

    $property = new ReflectionProperty(Log::class, 'logger');
    $property->setValue(null, $this->logger);
});

afterEach(function () {
    (new ReflectionProperty(Log::class, 'logger'))->setValue(null, null);
});

it('logs only event and channel for presence and private broadcasts', function (string $name) {
    $channel = loggingChannel($name);

    $channel->broadcast([
        'event' => 'pusher_internal:member_added',
        'channel' => $name,
        'data' => json_encode(['user_id' => 7, 'user_info' => ['email' => 'leak@example.com']]),
    ]);
    $channel->broadcast(['event' => 'App\\Evt', 'channel' => $name, 'data' => 'secret-body'], new FakeConnection);

    $logged = implode("\n", $this->logger->messages);

    expect($this->logger->messages)->toHaveCount(2)
        ->and($logged)->toContain($name)
        ->and($logged)->not->toContain('leak@example.com')
        ->and($logged)->not->toContain('secret-body');
})->with(['presence-room', 'private-orders']);

it('logs member_added on a public channel without its payload', function () {
    $channel = loggingChannel('test-channel');

    $channel->broadcast(['event' => 'pusher_internal:member_added', 'channel' => 'test-channel', 'data' => 'leak@example.com']);

    expect(implode($this->logger->messages))->not->toContain('leak@example.com');
});

it('still logs the full payload for public channel broadcasts', function () {
    $channel = loggingChannel('test-channel');

    $channel->broadcast(['event' => 'App\\Evt', 'channel' => 'test-channel', 'data' => 'public-body']);

    expect(implode($this->logger->messages))->toContain('public-body');
});

<?php

use Webpatser\Resonate\Contracts\ServerProvider;
use Webpatser\Resonate\Protocols\Pusher\MetricsHandler;
use Webpatser\Resonate\ResonateServiceProvider;
use Webpatser\Resonate\Scaling\Contracts\PubSubIncomingMessageHandler;
use Webpatser\Resonate\Scaling\Contracts\PubSubProvider;
use Webpatser\Resonate\Scaling\PusherPubSubIncomingMessageHandler;
use Webpatser\Resonate\Scaling\RedisPubSubProvider;
use Webpatser\Resonate\Scaling\ResonateServerProvider;

it('does not bind the scaling layer when scaling is disabled', function () {
    expect(config('reverb.servers.reverb.scaling.enabled'))->toBeFalse()
        ->and(app()->bound(ServerProvider::class))->toBeFalse()
        ->and(app()->bound(PubSubProvider::class))->toBeFalse()
        ->and(app()->bound(PubSubIncomingMessageHandler::class))->toBeFalse();
});

it('binds the scaling layer when scaling is enabled', function () {
    config()->set('reverb.servers.reverb.scaling.enabled', true);

    (new ResonateServiceProvider(app()))->register();

    expect(app()->bound(ServerProvider::class))->toBeTrue()
        ->and(app(ServerProvider::class))->toBeInstanceOf(ResonateServerProvider::class)
        ->and(app(ServerProvider::class)->shouldPublishEvents())->toBeTrue()
        ->and(app(PubSubIncomingMessageHandler::class))->toBeInstanceOf(PusherPubSubIncomingMessageHandler::class)
        ->and(app(PubSubProvider::class))->toBeInstanceOf(RedisPubSubProvider::class);
});

it('shares a single metrics handler when scaling is enabled', function () {
    config()->set('reverb.servers.reverb.scaling.enabled', true);

    (new ResonateServiceProvider(app()))->register();

    expect(app(MetricsHandler::class))->toBe(app(MetricsHandler::class));
});

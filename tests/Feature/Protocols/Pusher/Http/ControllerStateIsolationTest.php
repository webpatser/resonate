<?php

use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\Controller;
use Webpatser\Resonate\Protocols\Pusher\Http\Controllers\EventsController;
use Webpatser\Resonate\Server\Request as ResonateRequest;
use Webpatser\Resonate\Tests\Feature\Protocols\Pusher\Http\RequestSigner;

/*
 * Guards the fix for the cross-app state-bleed vulnerability: the Pusher REST
 * controllers are registered as singletons in the router and reused for the
 * lifetime of the process, so request-scoped state (the resolved application
 * and its channel manager) must never live on the controller instance. Under
 * concurrent fiber-handled requests, instance state would leak between requests
 * for different applications. It now lives on the per-request Request wrapper.
 */

it('does not declare request-scoped state on the base controller', function () {
    $reflection = new ReflectionClass(Controller::class);

    $properties = array_map(
        fn (ReflectionProperty $property) => $property->getName(),
        $reflection->getProperties(),
    );

    expect($properties)->not->toContain('application')
        ->and($properties)->not->toContain('channels')
        ->and($properties)->not->toContain('body')
        ->and($properties)->not->toContain('query');
});

it('keeps application and channel state on the per-request wrapper', function () {
    $application = app(ApplicationProvider::class)->findById('app-id');
    $channels = app(ChannelManager::class)->for($application);

    $request = new ResonateRequest(RequestSigner::post('/apps/app-id/events', null));

    expect($request->application())->toBeNull()
        ->and($request->channels())->toBeNull();

    $request->setApplication($application);
    $request->setChannels($channels);

    expect($request->application())->toBe($application)
        ->and($request->channels())->toBe($channels);

    // A second wrapper is fully independent: no shared singleton state.
    $other = new ResonateRequest(RequestSigner::post('/apps/app-id/events', null));

    expect($other->application())->toBeNull()
        ->and($other->channels())->toBeNull();
});

it('resolves the application fresh on every request for a reused controller instance', function () {
    $controller = new EventsController;

    $valid = fn (string $appId) => $controller->handleRequest(RequestSigner::post(
        "/apps/{$appId}/events",
        [
            'name' => 'NewEvent',
            'channel' => 'test-channel',
            'data' => json_encode(['some' => 'data']),
        ],
        routeParams: ['appId' => $appId],
    ));

    // First request resolves a real app. If the resolved application were
    // retained on the instance, the following unknown-app request would reuse
    // it instead of failing; it must 404 because state is request-scoped.
    expect($valid('app-id')->getStatus())->toBe(200)
        ->and($valid('unknown-app-id')->getStatus())->toBe(404)
        ->and($valid('app-id')->getStatus())->toBe(200);
});

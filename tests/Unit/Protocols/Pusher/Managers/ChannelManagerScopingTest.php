<?php

use Webpatser\Resonate\Application;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\Managers\ArrayChannelManager;
use Webpatser\Resonate\Tests\Fakes\FakeConnection;

/*
 * The channel manager is a container singleton, but every connection runs in
 * its own fiber. It used to carry a mutable application scope that `for()`
 * reassigned and returned `$this` from, so "capturing" a scoped manager
 * captured the shared object, not a snapshot. Any suspension between scoping
 * and a later read (a Redis publish, a socket write to a slow peer, buffering
 * a request body) let another fiber re-scope it underneath the first, and the
 * read then hit another tenant's channels, counts or connections.
 *
 * `for()` now returns an immutable view, so these tests pin the properties the
 * rest of the server relies on: a view cannot be re-scoped, views share their
 * underlying state, and an unscoped manager fails loudly instead of silently
 * inheriting whichever application was set last.
 */

beforeEach(function () {
    $this->manager = $this->app->make(ChannelManager::class);
    $this->apps = $this->app->make(ApplicationProvider::class)->all();
});

it('returns a new view rather than re-scoping itself', function () {
    $application = $this->apps->first();

    $view = $this->manager->for($application);

    expect($view)->not->toBe($this->manager)
        ->and($view->app()->id())->toBe($application->id())
        ->and($this->manager->app())->toBeNull();
});

it('keeps a held view pointing at its own application when another scope is taken', function () {
    $application = $this->apps->first();

    $held = $this->manager->for($application);

    // Stands in for another fiber scoping the shared manager mid-suspension.
    $other = $this->manager->for(new Application(
        id: 'other-app',
        key: 'other-key',
        secret: 'other-secret',
        pingInterval: 30,
        activityTimeout: 30,
        allowedOrigins: ['*'],
        maxMessageSize: 10_000,
    ));

    expect($held->app()->id())->toBe($application->id())
        ->and($other->app()->id())->toBe('other-app');
});

it('does not leak channels between applications', function () {
    $application = $this->apps->first();

    $this->manager->for($application)->findOrCreate('private-only-in-first');

    $other = $this->manager->for(new Application(
        id: 'other-app',
        key: 'other-key',
        secret: 'other-secret',
        pingInterval: 30,
        activityTimeout: 30,
        allowedOrigins: ['*'],
        maxMessageSize: 10_000,
    ));

    expect($other->find('private-only-in-first'))->toBeNull()
        ->and($this->manager->for($application)->find('private-only-in-first'))->not->toBeNull();
});

it('shares state between two views of the same application', function () {
    $application = $this->apps->first();

    $first = $this->manager->for($application);
    $second = $this->manager->for($application);

    $first->findOrCreate('private-shared');
    $first->addConnection(new FakeConnection);

    // Distinct view objects, one underlying registry.
    expect($second)->not->toBe($first)
        ->and($second->find('private-shared'))->not->toBeNull()
        ->and($second->connectionCount())->toBe(1);
});

it('counts connections per application rather than globally', function () {
    $application = $this->apps->first();

    $other = new Application(
        id: 'other-app',
        key: 'other-key',
        secret: 'other-secret',
        pingInterval: 30,
        activityTimeout: 30,
        allowedOrigins: ['*'],
        maxMessageSize: 10_000,
    );

    $this->manager->for($application)->addConnection(new FakeConnection);
    $this->manager->for($application)->addConnection(new FakeConnection);
    $this->manager->for($other)->addConnection(new FakeConnection);

    expect($this->manager->for($application)->connectionCount())->toBe(2)
        ->and($this->manager->for($other)->connectionCount())->toBe(1);
});

it('throws instead of guessing when the manager has never been scoped', function () {
    // Previously this silently used whichever application was scoped last.
    expect(fn () => (new ArrayChannelManager)->find('private-anything'))
        ->toThrow(RuntimeException::class, 'must be scoped to an application');
});

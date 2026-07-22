<?php

use Illuminate\Foundation\DevCommands;
use Webpatser\Resonate\Resonate;

/*
 * `DevCommands` keeps its registry in static properties that live for the
 * whole PHP process, not per-application-instance. `ResonateServiceProvider`
 * registers into it on every application boot, so each test here resets the
 * registry first and restores it afterwards to avoid leaking state into
 * unrelated tests.
 */

function resetDevCommandsRegistry(): array
{
    $reflection = new ReflectionClass(DevCommands::class);

    $properties = [
        'commands' => $reflection->getProperty('commands'),
        'only' => $reflection->getProperty('only'),
        'except' => $reflection->getProperty('except'),
        'colorCount' => $reflection->getProperty('colorCount'),
    ];

    foreach ($properties as &$property) {
        $property->setAccessible(true);
    }

    $original = array_map(fn ($property) => $property->getValue(), $properties);

    $properties['commands']->setValue(null, []);
    $properties['only']->setValue(null, []);
    $properties['except']->setValue(null, []);
    $properties['colorCount']->setValue(null, 0);

    return [$properties, $original];
}

function restoreDevCommandsRegistry(array $properties, array $original): void
{
    foreach ($properties as $key => $property) {
        $property->setValue(null, $original[$key]);
    }
}

beforeEach(function () {
    [$this->devCommandsProperties, $this->devCommandsOriginal] = resetDevCommandsRegistry();
});

afterEach(function () {
    restoreDevCommandsRegistry($this->devCommandsProperties, $this->devCommandsOriginal);
});

it('registers resonate:start as a dev command', function () {
    Resonate::registerDevCommands();

    $resonate = collect(DevCommands::commands())->firstWhere('name', 'resonate');

    expect($resonate)->not->toBeNull()
        ->and($resonate['command'])->toBe('php artisan resonate:start');
});

it('wires the dev command registration into the service provider boot', function () {
    app()->register(\Webpatser\Resonate\ResonateServiceProvider::class, force: true);

    $resonate = collect(DevCommands::commands())->firstWhere('name', 'resonate');

    expect($resonate)->not->toBeNull()
        ->and($resonate['command'])->toBe('php artisan resonate:start');
});

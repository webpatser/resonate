<?php

use Webpatser\Resonate\Plugins\PluginManager;
use Webpatser\Resonate\Tests\Fakes\FakeServerPlugin;

/*
 * Exercises ResonateServiceProvider::registerPlugins() - the container picks
 * the plugin classes up from `reverb.servers.reverb.plugins` config.
 */

function pluginManagerWith(array $plugins): PluginManager
{
    app('config')->set('reverb.servers.reverb.plugins', $plugins);
    app()->forgetInstance(PluginManager::class);

    return app(PluginManager::class);
}

it('registers no plugins when none are configured', function () {
    expect(pluginManagerWith([])->hasPlugins())->toBeFalse();
});

it('registers plugins listed in config', function () {
    expect(pluginManagerWith([FakeServerPlugin::class])->hasPlugins())->toBeTrue();
});

it('skips config entries that are not ServerPlugin instances', function () {
    expect(pluginManagerWith([stdClass::class])->hasPlugins())->toBeFalse();
});

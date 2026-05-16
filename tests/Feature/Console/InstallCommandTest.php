<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Webpatser\Resonate\Console\Commands\InstallCommand;

/*
 * The install command writes to `.env` and `config/*.php` relative to
 * `app()->basePath()`. To keep these tests hermetic, every case sets up a
 * temp directory laid out like a real Laravel project, points the
 * application at it, and tears the directory down afterwards.
 *
 * The orchestrator has not yet wired `InstallCommand` into the service
 * provider, so each test also registers the command with the test
 * Artisan kernel before invoking it.
 */

function bootInstallSandbox(string $envContents = "APP_NAME=Test\n", ?string $appPhp = null, ?string $broadcastingPhp = null): string
{
    $dir = sys_get_temp_dir().'/resonate-install-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($dir.'/config');
    File::ensureDirectoryExists($dir.'/routes');

    File::put($dir.'/.env', $envContents);

    // The install command offers to call `install:broadcasting` when
    // `routes/channels.php` is missing. Providing it short-circuits that
    // branch so the test stays focused on the file-mutation surface.
    File::put($dir.'/routes/channels.php', "<?php\n");

    File::put(
        $dir.'/config/app.php',
        $appPhp ?? <<<'PHP'
<?php

return [
    'name' => 'Test',
    'providers' => [
        // App\Providers\BroadcastServiceProvider::class,
        App\Providers\AppServiceProvider::class,
    ],
];

PHP
    );

    File::put(
        $dir.'/config/broadcasting.php',
        $broadcastingPhp ?? <<<'PHP'
<?php

return [
    'default' => env('BROADCAST_CONNECTION', 'null'),
    'connections' => [

        'pusher' => [
            'driver' => 'pusher',
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],
];

PHP
    );

    return $dir;
}

function tearDownInstallSandbox(string $dir): void
{
    if (is_dir($dir)) {
        File::deleteDirectory($dir);
    }
}

beforeEach(function () {
    // Register the install command on the test kernel; the orchestrator wires
    // this in `ResonateServiceProvider::boot()` for production but the test
    // harness boots before that hook lands.
    app(Kernel::class)->registerCommand(app(InstallCommand::class));

    $this->originalBasePath = app()->basePath();
    $this->originalCwd = getcwd();
    $this->originalConnections = config('broadcasting.connections', []);

    // Testbench ships a config/broadcasting.php with a 'reverb' connection,
    // so the loaded config flags `broadcasting.connections.reverb` as present
    // and the install command takes its early-return path. Strip the key from
    // the loaded config so the command exercises the mutation path against
    // the sandbox's `config/broadcasting.php` instead.
    $connections = config('broadcasting.connections', []);
    unset($connections['reverb']);
    config()->set('broadcasting.connections', $connections);
});

afterEach(function () {
    chdir($this->originalCwd);
    app()->setBasePath($this->originalBasePath);
    config()->set('broadcasting.connections', $this->originalConnections);
});

/*
 * `app()->environmentFile()` returns the bare filename `.env`, so File facade
 * calls in the command resolve it against the current working directory.
 * Tests chdir into the sandbox to keep that resolution consistent with the
 * basePath we've set.
 */
function pointAppAt(string $dir): void
{
    app()->setBasePath($dir);
    chdir($dir);
}

it('registers the resonate:install command on the artisan kernel', function () {
    $commands = array_keys(app(Kernel::class)->all());

    expect($commands)->toContain('resonate:install');
});

it('adds the Reverb environment variables to a fresh .env file', function () {
    $dir = bootInstallSandbox();

    try {
        pointAppAt($dir);

        $exitCode = Artisan::call('resonate:install', [
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(0);

        $env = File::get($dir.'/.env');

        expect($env)->toContain('REVERB_APP_ID=')
            ->and($env)->toContain('REVERB_APP_KEY=')
            ->and($env)->toContain('REVERB_APP_SECRET=')
            ->and($env)->toContain('REVERB_HOST="localhost"')
            ->and($env)->toContain('REVERB_PORT=8080')
            ->and($env)->toContain('REVERB_SCHEME=http')
            ->and($env)->toContain('VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"')
            ->and($env)->toContain('VITE_REVERB_HOST="${REVERB_HOST}"')
            ->and($env)->toContain('VITE_REVERB_PORT="${REVERB_PORT}"')
            ->and($env)->toContain('VITE_REVERB_SCHEME="${REVERB_SCHEME}"');
    } finally {
        tearDownInstallSandbox($dir);
    }
});

it('does not duplicate existing environment variables', function () {
    $dir = bootInstallSandbox(
        "APP_NAME=Test\nREVERB_APP_KEY=already-set\n"
    );

    try {
        pointAppAt($dir);

        Artisan::call('resonate:install', [
            '--no-interaction' => true,
        ]);

        $env = File::get($dir.'/.env');

        // The pre-existing value is preserved (no second REVERB_APP_KEY assignment).
        // VITE_REVERB_APP_KEY references but does not redefine the key.
        expect($env)->toContain('REVERB_APP_KEY=already-set')
            ->and(preg_match_all('/^REVERB_APP_KEY=/m', $env))->toBe(1);
    } finally {
        tearDownInstallSandbox($dir);
    }
});

it('adds the reverb connection block to config/broadcasting.php', function () {
    $dir = bootInstallSandbox();

    try {
        pointAppAt($dir);

        Artisan::call('resonate:install', [
            '--no-interaction' => true,
        ]);

        $broadcasting = File::get($dir.'/config/broadcasting.php');

        expect($broadcasting)->toContain("'reverb' => [")
            ->and($broadcasting)->toContain("'driver' => 'reverb'")
            ->and($broadcasting)->toContain("env('REVERB_APP_KEY')")
            ->and($broadcasting)->toContain("env('REVERB_APP_SECRET')")
            ->and($broadcasting)->toContain("env('REVERB_APP_ID')");
    } finally {
        tearDownInstallSandbox($dir);
    }
});

it('uncomments the BroadcastServiceProvider when present in config/app.php', function () {
    $dir = bootInstallSandbox();

    try {
        pointAppAt($dir);

        Artisan::call('resonate:install', [
            '--no-interaction' => true,
        ]);

        $app = File::get($dir.'/config/app.php');

        expect($app)->toContain('App\Providers\BroadcastServiceProvider::class')
            ->and($app)->not->toContain('// App\Providers\BroadcastServiceProvider::class');
    } finally {
        tearDownInstallSandbox($dir);
    }
});

it('skips the BroadcastServiceProvider step on Laravel 13 layouts without config/app.php providers', function () {
    // Laravel 13 skeleton registers providers in bootstrap/providers.php, and
    // config/app.php may not contain the BroadcastServiceProvider comment marker.
    $appPhp = <<<'PHP'
<?php

return [
    'name' => 'Test',
];

PHP;

    $dir = bootInstallSandbox(envContents: "APP_NAME=Test\n", appPhp: $appPhp);

    try {
        pointAppAt($dir);

        $exitCode = Artisan::call('resonate:install', [
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(0);

        // config/app.php is unchanged.
        $app = File::get($dir.'/config/app.php');
        expect($app)->toBe($appPhp);
    } finally {
        tearDownInstallSandbox($dir);
    }
});

it('sets BROADCAST_CONNECTION to reverb when an existing value is present', function () {
    $dir = bootInstallSandbox(
        "APP_NAME=Test\nBROADCAST_CONNECTION=log\n"
    );

    try {
        pointAppAt($dir);

        Artisan::call('resonate:install', [
            '--no-interaction' => true,
        ]);

        $env = File::get($dir.'/.env');

        expect($env)->toContain('BROADCAST_CONNECTION=reverb')
            ->and($env)->not->toContain('BROADCAST_CONNECTION=log');
    } finally {
        tearDownInstallSandbox($dir);
    }
});

it('reports a Resonate-branded success message', function () {
    $dir = bootInstallSandbox();

    try {
        pointAppAt($dir);

        Artisan::call('resonate:install', [
            '--no-interaction' => true,
        ]);

        $output = Artisan::output();

        expect($output)->toContain('Resonate');
    } finally {
        tearDownInstallSandbox($dir);
    }
});

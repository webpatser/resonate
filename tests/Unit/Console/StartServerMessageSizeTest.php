<?php

use Webpatser\Resonate\Console\Commands\StartServer;

/*
 * Pins the transport message size fallback handed to the server factory.
 *
 * It used to be the largest configured application limit, applied to every
 * connection on the process, which is what let a client of a small app buffer
 * up to a large app's limit. Per-connection limits now come from the resolved
 * application, so this value only covers an upgrade whose app key resolves to
 * nothing, and it is the smallest configured limit rather than the largest.
 */

/**
 * Build the command with its size resolution promoted to public.
 */
function startServerCommand(): StartServer
{
    $command = new class extends StartServer
    {
        public function callFallbackMessageSize(): int
        {
            return $this->fallbackMessageSize();
        }
    };

    $command->setLaravel(app());

    return $command;
}

it('falls back to the smallest configured application limit', function () {
    config()->set('reverb.apps.apps', [
        ['app_id' => 'small', 'max_message_size' => 10_000],
        ['app_id' => 'large', 'max_message_size' => 10_000_000],
        ['app_id' => 'middling', 'max_message_size' => 250_000],
    ]);

    expect(startServerCommand()->callFallbackMessageSize())->toBe(10_000);
});

it('ignores applications with no configured limit', function () {
    config()->set('reverb.apps.apps', [
        ['app_id' => 'unset'],
        ['app_id' => 'zero', 'max_message_size' => 0],
        ['app_id' => 'set', 'max_message_size' => 64_000],
    ]);

    expect(startServerCommand()->callFallbackMessageSize())->toBe(64_000);
});

it('defaults when no application configures a limit', function () {
    config()->set('reverb.apps.apps', []);

    expect(startServerCommand()->callFallbackMessageSize())->toBe(10_000);
});

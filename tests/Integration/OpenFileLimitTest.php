<?php

use Webpatser\Resonate\Tests\Support\ServerProcess;

/*
 * A server started under a low soft open file limit (1024 is systemd's
 * default) raises it as far as the hard limit allows and says so, and warns
 * with the numbers and the fix when the hard limit is too low as well.
 */

beforeEach(function () {
    if (! function_exists('posix_getrlimit') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the posix extension.');
    }
});

it('raises a low soft open file limit and says so', function () {
    [, $hard] = posix_getrlimit(POSIX_RLIMIT_NOFILE);

    if ($hard !== 'unlimited' && (int) $hard < 4_352) {
        $this->markTestSkipped("The hard open file limit ({$hard}) is too low to raise to.");
    }

    $server = ServerProcess::start('auto', ulimit: '-S -n 256');

    try {
        expect($server->log())->toContain('Raised the open file limit from 256 to')
            ->not->toContain('below the');
    } finally {
        $server->stop();
    }
})->group('integration');

it('warns when the hard open file limit is too low as well', function () {
    $server = ServerProcess::start('auto', ulimit: '-S -n 256 && ulimit -H -n 512');

    try {
        expect($server->log())->toContain('The open file limit is 512 (hard limit 512), below the 4352 descriptors')
            ->toContain('LimitNOFILE=')
            ->toContain('ulimit -n');
    } finally {
        $server->stop();
    }
})->group('integration');

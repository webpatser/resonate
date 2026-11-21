<?php

use Webpatser\Resonate\Exceptions\InvalidConfiguration;
use Webpatser\Resonate\Server\Factory;

/*
 * The listener carries the socket options every accepted connection inherits:
 * TCP_NODELAY always (TLS connections included, which a per-connection
 * setsockopt could not reach), SO_REUSEPORT for `resonate:reload`, and TCP
 * keep-alive only when `tcp_keepalive` asks for it.
 */

it('always binds with TCP_NODELAY and SO_REUSEPORT and without keep-alive by default', function () {
    $socket = Factory::bindContext()->toStreamContextArray()['socket'];

    expect($socket['tcp_nodelay'])->toBeTrue()
        ->and($socket['so_reuseport'])->toBeTrue()
        ->and($socket)->not->toHaveKey('so_keepalive');
});

it('turns keep-alive on with the operating system timings', function () {
    $socket = Factory::bindContext(tcpKeepAlive: true)->toStreamContextArray()['socket'];

    expect($socket['so_keepalive'])->toBeTrue()
        ->and($socket)->not->toHaveKey('tcp_keepidle');
});

it('turns keep-alive on with the configured idle seconds', function () {
    $socket = Factory::bindContext(tcpKeepAlive: '60')->toStreamContextArray()['socket'];

    expect($socket['so_keepalive'])->toBeTrue()
        ->and($socket['tcp_keepidle'])->toBe(60);
});

it('resolves the tcp_keepalive setting', function (mixed $value, bool|int $expected) {
    expect(Factory::keepAlive($value))->toBe($expected);
})->with([
    'null' => [null, false],
    'false' => [false, false],
    'empty string' => ['', false],
    'zero' => [0, false],
    'zero string' => ['0', false],
    'true' => [true, true],
    'seconds' => [30, 30],
    'seconds string' => ['45', 45],
]);

it('rejects an invalid tcp_keepalive setting', function (mixed $value) {
    Factory::keepAlive($value);
})->with([
    'negative' => [-5],
    'word' => ['often'],
    'array' => [[1]],
])->throws(InvalidConfiguration::class, 'tcp_keepalive');

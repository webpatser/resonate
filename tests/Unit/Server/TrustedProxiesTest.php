<?php

use Webpatser\Resonate\Exceptions\InvalidConfiguration;
use Webpatser\Resonate\Server\TrustedProxies;

/*
 * Exercises TrustedProxies::clientAddress().
 *
 * The client rate-limit dimension used to key on the TCP peer alone, so behind
 * a reverse proxy every client shared the proxy's quota: one socket sending
 * junk frames got everyone else rate limited, and with `terminate_on_limit`
 * disconnected. `X-Forwarded-For` is now honoured, but only from a configured
 * proxy, and only up to the first address that proxy did not vouch for.
 */

it('ignores X-Forwarded-For when no proxy is trusted', function () {
    $proxies = new TrustedProxies;

    expect($proxies->isEmpty())->toBeTrue()
        ->and($proxies->clientAddress('10.0.0.2', ['203.0.113.9']))->toBe('10.0.0.2');
});

it('ignores a spoofed X-Forwarded-For from a peer that is not trusted', function () {
    $proxies = new TrustedProxies(['10.0.0.0/8']);

    // A client connecting directly picks whatever address it likes here, so
    // believing it would let it hop between quotas at will.
    expect($proxies->clientAddress('198.51.100.7', ['203.0.113.9']))->toBe('198.51.100.7');
});

it('uses the forwarded address when the peer is a trusted proxy', function () {
    $proxies = new TrustedProxies(['10.0.0.0/8']);

    expect($proxies->clientAddress('10.0.0.2', ['203.0.113.9']))->toBe('203.0.113.9');
});

it('uses the right-most untrusted address of a multi-hop chain', function () {
    $proxies = new TrustedProxies(['10.0.0.0/8', '192.168.1.1']);

    // edge proxy 192.168.1.1 appended the client, internal 10.0.0.5 appended
    // the edge proxy; the peer is the last internal hop.
    expect($proxies->clientAddress('10.0.0.2', ['203.0.113.9, 192.168.1.1, 10.0.0.5']))
        ->toBe('203.0.113.9');
});

it('never reads entries a client prepended to the chain', function () {
    $proxies = new TrustedProxies(['10.0.0.0/8']);

    // The client sent `X-Forwarded-For: 192.0.2.1`; the proxy appended the
    // address it actually saw. Whatever the client wrote stays to the left.
    expect($proxies->clientAddress('10.0.0.2', ['192.0.2.1, 203.0.113.9']))->toBe('203.0.113.9')
        ->and($proxies->clientAddress('10.0.0.2', ['10.0.0.9, 203.0.113.9']))->toBe('203.0.113.9');
});

it('reads repeated X-Forwarded-For header lines as one chain', function () {
    $proxies = new TrustedProxies(['10.0.0.0/8']);

    expect($proxies->clientAddress('10.0.0.2', ['192.0.2.1', '203.0.113.9, 10.0.0.5']))->toBe('203.0.113.9');
});

it('uses the left-most address when the whole chain is trusted', function () {
    $proxies = new TrustedProxies(['10.0.0.0/8']);

    expect($proxies->clientAddress('10.0.0.2', ['10.1.2.3, 10.0.0.5']))->toBe('10.1.2.3');
});

it('keeps the proxy address when a trusted peer sends no X-Forwarded-For', function () {
    $proxies = new TrustedProxies(['10.0.0.0/8']);

    expect($proxies->clientAddress('10.0.0.2', []))->toBe('10.0.0.2')
        ->and($proxies->clientAddress('10.0.0.2', ['']))->toBe('10.0.0.2');
});

it('stops at a malformed entry instead of reading past it', function () {
    $proxies = new TrustedProxies(['10.0.0.0/8']);

    expect($proxies->clientAddress('10.0.0.2', ['203.0.113.9, unknown']))->toBe('10.0.0.2')
        ->and($proxies->clientAddress('10.0.0.2', ['203.0.113.9, garbage, 10.0.0.5']))->toBe('10.0.0.5');
});

it('strips ports and canonicalizes forwarded addresses', function (string $hop, string $expected) {
    $proxies = new TrustedProxies(['10.0.0.0/8']);

    expect($proxies->clientAddress('10.0.0.2', [$hop]))->toBe($expected);
})->with([
    'ipv4 with port' => ['203.0.113.9:5678', '203.0.113.9'],
    'bracketed ipv6' => ['[2001:DB8::1]', '2001:db8::1'],
    'bracketed ipv6 with port' => ['[2001:db8:0:0::1]:443', '2001:db8::1'],
    'bare ipv6' => ['2001:0db8::0001', '2001:db8::1'],
    'ipv4-mapped ipv6' => ['::ffff:203.0.113.9', '203.0.113.9'],
]);

it('matches IPv6 ranges and IPv4-mapped peers', function () {
    $proxies = new TrustedProxies(['fd00::/8', '10.0.0.0/8']);

    expect($proxies->clientAddress('fd12:3456::1', ['2001:db8::7']))->toBe('2001:db8::7')
        ->and($proxies->clientAddress('::ffff:10.0.0.2', ['203.0.113.9']))->toBe('203.0.113.9')
        ->and($proxies->clientAddress('fe80::1', ['203.0.113.9']))->toBe('fe80::1');
});

it('honours prefix lengths that do not fall on a byte boundary', function () {
    $proxies = new TrustedProxies(['172.16.0.0/12']);

    expect($proxies->isTrusted('172.16.0.1'))->toBeTrue()
        ->and($proxies->isTrusted('172.31.255.255'))->toBeTrue()
        ->and($proxies->isTrusted('172.32.0.1'))->toBeFalse()
        ->and($proxies->isTrusted('172.15.255.255'))->toBeFalse();
});

it('treats a bare address as a single host', function () {
    $proxies = new TrustedProxies(['10.0.0.2', '::ffff:192.0.2.0/120']);

    expect($proxies->isTrusted('10.0.0.2'))->toBeTrue()
        ->and($proxies->isTrusted('10.0.0.3'))->toBeFalse()
        ->and($proxies->isTrusted('192.0.2.200'))->toBeTrue()
        ->and($proxies->isTrusted('192.0.3.1'))->toBeFalse();
});

it('does not trust a peer that is not an IP address', function () {
    $proxies = new TrustedProxies(['0.0.0.0/0']);

    expect($proxies->clientAddress('unix:/run/resonate.sock', ['203.0.113.9']))->toBe('unix:/run/resonate.sock')
        ->and($proxies->clientAddress(null, ['203.0.113.9']))->toBeNull();
});

it('builds from a list or a comma-separated string', function () {
    expect(TrustedProxies::fromConfig(null)->isEmpty())->toBeTrue()
        ->and(TrustedProxies::fromConfig('')->isEmpty())->toBeTrue()
        ->and(TrustedProxies::fromConfig([])->isEmpty())->toBeTrue()
        ->and(TrustedProxies::fromConfig('10.0.0.0/8, fd00::/8')->isTrusted('fd00::1'))->toBeTrue()
        ->and(TrustedProxies::fromConfig(['10.0.0.0/8', ' '])->isTrusted('10.9.9.9'))->toBeTrue();
});

it('refuses entries that are not a CIDR range', function (mixed $value) {
    expect(fn () => TrustedProxies::fromConfig($value))->toThrow(InvalidConfiguration::class, 'trusted_proxies');
})->with([
    'hostname' => [['proxy.internal']],
    'prefix too long' => [['10.0.0.0/33']],
    'ipv6 prefix too long' => [['fd00::/129']],
    'non-numeric prefix' => [['10.0.0.0/eight']],
    'negative prefix' => [['10.0.0.0/-1']],
    'not a list' => [42],
    'nested list' => [[['10.0.0.0/8']]],
]);

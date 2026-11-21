<?php

use Fledge\Async\Http\Server\SocketHttpServer;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Webpatser\Resonate\Exceptions\InvalidConfiguration;
use Webpatser\Resonate\Server\Factory;

/*
 * Exercises the transport connection limits wired through Server\Factory.
 *
 * The socket server used to be created with fledge-fiber's defaults of 1000
 * connections in total and 10 per IP. Behind a reverse proxy every client
 * shares the proxy's address, so the 11th client was dropped silently. These
 * tests pin that both limits are forwarded to the transport, that they are
 * unlimited unless configured, and that a malformed value is rejected.
 *
 * The transport announces its effective limits through the PSR logger while
 * it is built, which is what these tests read back. The socket server builder
 * is promoted to public by a thin test subclass, in the same style as
 * ApplicationClientFactoryTest.
 */

/**
 * A PSR logger that records every message it receives.
 */
function recordingLogger(): LoggerInterface
{
    return new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $messages = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }
    };
}

/**
 * Build the socket server the way Factory::make() does and return the limit
 * notices the transport logged.
 *
 * @return list<string>
 */
function transportNoticesFor(mixed $connectionLimit, mixed $connectionLimitPerIp): array
{
    $factory = new class extends Factory
    {
        public static function callMakeSocketServer(LoggerInterface $logger, int $total, int $perIp): SocketHttpServer
        {
            return self::makeSocketServer($logger, 10_000, $total, $perIp);
        }
    };

    $logger = recordingLogger();

    $server = $factory::callMakeSocketServer(
        $logger,
        Factory::connectionLimit($connectionLimit, 'connection_limit'),
        Factory::connectionLimit($connectionLimitPerIp, 'connection_limit_per_ip'),
    );

    expect($server)->toBeInstanceOf(SocketHttpServer::class);

    return $logger->messages;
}

it('is unlimited by default instead of 1000 total and 10 per IP', function () {
    $notices = transportNoticesFor(null, null);

    expect($notices)
        ->toContain(sprintf('Total client connections are limited to %d.', PHP_INT_MAX))
        ->toContain(sprintf('Client connections are limited to %d per IP address (excluding localhost).', PHP_INT_MAX))
        ->not->toContain('Total client connections are limited to 1000.')
        ->not->toContain('Client connections are limited to 10 per IP address (excluding localhost).');
});

it('forwards configured limits to the transport', function () {
    $notices = transportNoticesFor(5000, 250);

    expect($notices)
        ->toContain('Total client connections are limited to 5000.')
        ->toContain('Client connections are limited to 250 per IP address (excluding localhost).');
});

it('accepts numeric strings as they arrive from the environment', function () {
    $notices = transportNoticesFor('2000', '50');

    expect($notices)
        ->toContain('Total client connections are limited to 2000.')
        ->toContain('Client connections are limited to 50 per IP address (excluding localhost).');
});

it('treats null, an empty string and 0 as unlimited', function (mixed $value) {
    expect(Factory::connectionLimit($value))->toBe(PHP_INT_MAX);
})->with([
    'null' => [null],
    'empty string' => [''],
    'zero' => [0],
    'zero string' => ['0'],
]);

it('rejects malformed limits at startup', function (mixed $value) {
    Factory::connectionLimit($value, 'connection_limit_per_ip');
})->with([
    'negative' => [-1],
    'negative string' => ['-5'],
    'word' => ['many'],
    'float string' => ['1.5'],
    'array' => [[10]],
])->throws(InvalidConfiguration::class, 'servers.reverb.connection_limit_per_ip');

it('keeps Factory::make() limits as optional trailing parameters', function () {
    $parameters = (new ReflectionMethod(Factory::class, 'make'))->getParameters();
    $byName = array_column(array_map(fn (ReflectionParameter $p) => [$p->getName(), $p], $parameters), 1, 0);

    expect(array_slice(array_keys($byName), -3))->toBe(['connectionLimit', 'connectionLimitPerIp', 'tcpKeepAlive'])
        ->and($byName['connectionLimit']->isOptional())->toBeTrue()
        ->and($byName['connectionLimit']->getDefaultValue())->toBeNull()
        ->and($byName['connectionLimitPerIp']->isOptional())->toBeTrue()
        ->and($byName['connectionLimitPerIp']->getDefaultValue())->toBeNull()
        ->and($byName['tcpKeepAlive']->isOptional())->toBeTrue()
        ->and($byName['tcpKeepAlive']->getDefaultValue())->toBeNull();
});

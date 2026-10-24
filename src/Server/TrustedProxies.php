<?php

namespace Webpatser\Resonate\Server;

use Webpatser\Resonate\Exceptions\InvalidConfiguration;

/**
 * Attributes a connection to a client address when it arrives through a reverse proxy.
 *
 * Without this every connection behind a proxy reported the proxy's address,
 * so the client rate-limit dimension became one shared bucket: a single socket
 * sending junk frames exhausted it, and with `terminate_on_limit` everyone else
 * behind that proxy was disconnected along with it.
 *
 * `X-Forwarded-For` is only believed when the TCP peer is a configured trusted
 * proxy. From any other peer the header is client-supplied and ignored, since
 * honouring it would let anyone pick the bucket they are counted in. From a
 * trusted peer the chain is walked right to left (each proxy appends the
 * address it received the connection from) and the first address that is not
 * itself a trusted proxy is the client. Entries to the left of it were written
 * by that client and are never read.
 */
final class TrustedProxies
{
    /**
     * The trusted networks as packed address and prefix length pairs.
     *
     * @var list<array{0: string, 1: int}>
     */
    protected array $networks = [];

    /**
     * Create a new trusted proxy list.
     *
     * @param  list<string>  $proxies  CIDR ranges or bare addresses, IPv4 or IPv6.
     *
     * @throws InvalidConfiguration
     */
    public function __construct(array $proxies = [])
    {
        foreach ($proxies as $proxy) {
            $this->networks[] = $this->parseNetwork($proxy);
        }
    }

    /**
     * Build the list from the `servers.reverb.trusted_proxies` config value.
     *
     * Accepts a list or a comma-separated string, so an environment variable
     * can be passed through unprocessed. Blank entries are skipped.
     *
     * @throws InvalidConfiguration
     */
    public static function fromConfig(mixed $value): self
    {
        if ($value === null || $value === '' || $value === false) {
            return new self;
        }

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            throw new InvalidConfiguration(
                'reverb.servers.reverb.trusted_proxies must be a list of CIDR ranges.'
            );
        }

        $proxies = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                throw new InvalidConfiguration(
                    'reverb.servers.reverb.trusted_proxies must be a list of CIDR ranges.'
                );
            }

            if (($entry = trim($entry)) !== '') {
                $proxies[] = $entry;
            }
        }

        return new self($proxies);
    }

    /**
     * Determine whether no proxy is trusted.
     */
    public function isEmpty(): bool
    {
        return $this->networks === [];
    }

    /**
     * Determine whether the given address belongs to a trusted proxy.
     */
    public function isTrusted(string $address): bool
    {
        $packed = $this->pack($address);

        if ($packed === null) {
            return false;
        }

        foreach ($this->networks as [$network, $bits]) {
            if ($this->contains($network, $bits, $packed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the client address for a connection.
     *
     * Returns the peer unchanged unless it is a trusted proxy. From a trusted
     * peer it returns the right-most `X-Forwarded-For` entry that is not a
     * trusted proxy, or the left-most entry when the whole chain is trusted.
     * A malformed entry stops the walk at the last address that was valid, so
     * a chain that cannot be read attributes the connection to the proxy, as
     * if no header had been sent, rather than to anything the client wrote.
     *
     * @param  list<string>  $forwardedFor  The `X-Forwarded-For` header values, in the order received.
     */
    public function clientAddress(?string $peer, array $forwardedFor): ?string
    {
        if ($peer === null || $peer === '' || ! $this->isTrusted($peer)) {
            return $peer;
        }

        $client = $peer;

        $hops = explode(',', implode(',', $forwardedFor));

        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $address = $this->normalizeHop($hops[$i]);

            if ($address === null) {
                break;
            }

            $client = $address;

            if (! $this->isTrusted($address)) {
                break;
            }
        }

        return $client;
    }

    /**
     * Reduce one `X-Forwarded-For` entry to a canonical IP address.
     *
     * Some proxies append the port (`192.0.2.1:5678`, `[2001:db8::1]:443`),
     * which is stripped. Canonicalizing keeps one client on one limiter key
     * however its proxy spells the address.
     */
    protected function normalizeHop(string $hop): ?string
    {
        $hop = trim($hop);

        if (str_starts_with($hop, '[')) {
            $end = strpos($hop, ']');

            if ($end === false) {
                return null;
            }

            $hop = substr($hop, 1, $end - 1);
        } elseif (substr_count($hop, ':') === 1) {
            $hop = strstr($hop, ':', true);
        }

        $packed = $this->pack((string) $hop);

        if ($packed === null) {
            return null;
        }

        return inet_ntop($packed) ?: null;
    }

    /**
     * Pack an address to its binary form, folding IPv4-mapped IPv6 to IPv4.
     *
     * Folding lets a dual-stack listener reporting `::ffff:10.0.0.1` match a
     * configured `10.0.0.0/8`.
     */
    protected function pack(string $address): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($address);

        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return substr($packed, 12);
        }

        return $packed;
    }

    /**
     * Parse one configured entry into a packed network and prefix length.
     *
     * @return array{0: string, 1: int}
     *
     * @throws InvalidConfiguration
     */
    protected function parseNetwork(string $proxy): array
    {
        $proxy = trim($proxy);

        [$address, $bits] = str_contains($proxy, '/')
            ? explode('/', $proxy, 2)
            : [$proxy, null];

        $packed = $this->pack($address);

        if ($packed === null) {
            throw new InvalidConfiguration("reverb.servers.reverb.trusted_proxies entry [{$proxy}] is not an IP address or CIDR range.");
        }

        $mapped = strlen((string) inet_pton($address)) === 16 && strlen($packed) === 4;

        $max = strlen($packed) * 8;

        if ($bits === null) {
            return [$packed, $max];
        }

        if (! ctype_digit($bits)) {
            throw new InvalidConfiguration("reverb.servers.reverb.trusted_proxies entry [{$proxy}] has an invalid prefix length.");
        }

        // An IPv4-mapped range (`::ffff:10.0.0.0/104`) was folded to IPv4 by
        // pack(), so its prefix length is shifted by the 96 mapping bits.
        $bits = (int) $bits - ($mapped ? 96 : 0);

        if ($bits < 0 || $bits > $max) {
            throw new InvalidConfiguration("reverb.servers.reverb.trusted_proxies entry [{$proxy}] has an invalid prefix length.");
        }

        return [$packed, $bits];
    }

    /**
     * Determine whether the packed address falls inside the packed network.
     */
    protected function contains(string $network, int $bits, string $address): bool
    {
        if (strlen($network) !== strlen($address)) {
            return false;
        }

        $bytes = intdiv($bits, 8);

        if ($bytes > 0 && strncmp($network, $address, $bytes) !== 0) {
            return false;
        }

        $remainder = $bits % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($network[$bytes]) & $mask) === (ord($address[$bytes]) & $mask);
    }
}

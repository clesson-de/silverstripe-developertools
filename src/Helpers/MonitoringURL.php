<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Helpers;

use DomainException;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Restricts monitoring destinations to unambiguous HTTPS URLs and global IPs.
 *
 * DNS is resolved again for every request; the client pins the validated result.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Helpers
 */
class MonitoringURL
{
    use Injectable;
    use Configurable;

    /** @config Exact DNS hostnames allowed to resolve to local addresses in dev only. */
    private static array $dev_allowed_hosts = [];

    /** Local development networks; link-local metadata and other reserved ranges stay blocked. */
    private const DEV_NETWORKS = [
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8',
        'fc00::/7', '::1/128',
    ];

    /** IPv4 networks which are not globally routable customer destinations. */
    private const IPV4_DENY = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
        '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];
    /** Special IPv6 allocations within the otherwise global 2000::/3 block. */
    private const IPV6_DENY = ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];

    /** Normalizes host, default port and trailing slash without accepting URL credentials. */
    public function normalize(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || strlen($url) > 512 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts))
        ) {
            throw new DomainException('Invalid monitoring URL.');
        }
        $host = $this->host($parts['host']);
        $port = $parts['port'] ?? 443;
        $path = $this->path($parts['path'] ?? '');
        if ($port < 1 || $port > 65535) {
            throw new DomainException('Invalid monitoring port.');
        }
        return 'https://' . $host . ($port === 443 ? '' : ':' . $port) . $path;
    }

    /** Rejects ambiguous host spellings, local names and non-public IP literals. */
    private function host(string $host): string
    {
        $host = strtolower($host);
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->assertPublicAddress($ip);
            $canonical = inet_ntop(inet_pton($ip));
            return str_contains($canonical, ':') ? '[' . $canonical . ']' : $canonical;
        }
        if (strlen($host) > 253 || preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D', $host) !== 1
            || preg_match('/\.(?:localhost|local|internal|invalid|test|example|onion)\z/', $host)
        ) {
            throw new DomainException('Invalid monitoring host.');
        }
        return $host;
    }

    /** Allows installation paths, but not encoded separators or traversal. */
    private function path(string $path): string
    {
        if (preg_match('#\A[/a-zA-Z0-9._~-]*\z#D', $path) !== 1
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) || str_contains($path, '//')
        ) {
            throw new DomainException('Invalid monitoring path.');
        }
        return rtrim($path, '/');
    }

    /** Resolves both address families and rejects the entire response if any answer is unsafe. */
    public function publicAddresses(string $host): array
    {
        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
        if (!$addresses) {
            throw new DomainException('Monitoring host could not be resolved.');
        }
        foreach ($addresses as $address) {
            if ($this->allowsDevelopmentAddress($host, $address)) {
                continue;
            }
            $this->assertPublicAddress($address);
        }
        return array_values(array_unique($addresses));
    }

    /** Never permits wildcards, IP literals or an exception outside development. */
    private function allowsDevelopmentAddress(string $host, string $address): bool
    {
        if (!Director::isDev() || filter_var($host, FILTER_VALIDATE_IP)
            || !in_array(strtolower($host), static::config()->get('dev_allowed_hosts'), true)
            || !filter_var($address, FILTER_VALIDATE_IP)
        ) {
            return false;
        }
        foreach (self::DEV_NETWORKS as $network) {
            if ($this->inNetwork($address, $network)) {
                return true;
            }
        }
        return false;
    }

    /** DNS seam for deterministic tests; libc resolution is never used for the connection. */
    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            throw new DomainException('Monitoring DNS lookup failed.');
        }
        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip']) || isset($record['ipv6'])) {
                $addresses[] = $record['ip'] ?? $record['ipv6'];
            }
        }
        return $addresses;
    }

    /** Rejects private, reserved, mapped, transition and non-global unicast addresses. */
    public function assertPublicAddress(string $address): void
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new DomainException('Monitoring destination is not public.');
        }
        $ipv6 = str_contains($address, ':');
        if ($ipv6 && !$this->inNetwork($address, '2000::/3')) {
            throw new DomainException('Monitoring destination is not public.');
        }
        foreach ($ipv6 ? self::IPV6_DENY : self::IPV4_DENY as $network) {
            if ($this->inNetwork($address, $network)) {
                throw new DomainException('Monitoring destination is not public.');
            }
        }
    }

    /** Compares network prefixes without converting IPv6 to lossy integers. */
    private function inNetwork(string $address, string $network): bool
    {
        [$base, $bits] = explode('/', $network);
        $address = inet_pton($address);
        $base = inet_pton($base);
        if (strlen($address) !== strlen($base)) {
            return false;
        }
        $bytes = intdiv((int) $bits, 8);
        $remaining = (int) $bits % 8;
        return substr($address, 0, $bytes) === substr($base, 0, $bytes)
            && ($remaining === 0 || ((ord($address[$bytes]) ^ ord($base[$bytes])) & (255 << (8 - $remaining))) === 0);
    }
}

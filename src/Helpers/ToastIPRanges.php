<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Helpers;

use DomainException;

/**
 * Validates and matches inclusive binary IPv4/IPv6 address intervals.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Helpers
 */
final class ToastIPRanges
{
    /** Parses every rule before matching, so malformed rules are never ignored. */
    public static function parse(string $rules): array
    {
        $entries = preg_split('/[,\r\n]+/', trim($rules), -1, PREG_SPLIT_NO_EMPTY);
        $ranges = [];
        foreach ($entries as $entry) {
            if (trim($entry) !== '') {
                $ranges[] = self::interval(trim($entry));
            }
        }
        if ($ranges === [] && trim($rules) !== '') {
            throw new DomainException('IP restrictions must contain at least one valid address or range.');
        }
        return $ranges;
    }

    /** Empty rules allow every visitor; a missing visitor IP never matches restricted rules. */
    public static function matches(string $rules, ?string $ip): bool
    {
        $ranges = self::parse($rules);
        if ($ranges === []) {
            return true;
        }
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $address = inet_pton($ip);
        foreach ($ranges as [$start, $end]) {
            if (strlen($address) === strlen($start) && strcmp($address, $start) >= 0 && strcmp($address, $end) <= 0) {
                return true;
            }
        }
        return false;
    }

    /** Converts single addresses, CIDR networks and explicit ranges into intervals. */
    private static function interval(string $entry): array
    {
        if (str_contains($entry, '/')) {
            return self::network($entry);
        }
        $parts = explode('-', $entry);
        if (count($parts) > 2) {
            throw new DomainException('Invalid IP range.');
        }
        $start = self::address(trim($parts[0]));
        $end = self::address(trim($parts[1] ?? $parts[0]));
        if (strlen($start) !== strlen($end) || strcmp($start, $end) > 0) {
            throw new DomainException('IP ranges require ordered addresses from the same family.');
        }
        return [$start, $end];
    }

    /** Requires a complete, unambiguous IPv4 or IPv6 address. */
    private static function address(string $ip): string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new DomainException('Invalid IP address.');
        }
        return inet_pton($ip);
    }

    /** Builds both bounds from a validated CIDR prefix, including /0 networks. */
    private static function network(string $entry): array
    {
        $parts = explode('/', $entry);
        $address = self::address(trim($parts[0]));
        if (count($parts) !== 2 || !preg_match('/^(0|[1-9][0-9]{0,2})$/D', $parts[1])) {
            throw new DomainException('Invalid CIDR prefix.');
        }
        $prefix = (int) $parts[1];
        if ($prefix > strlen($address) * 8) {
            throw new DomainException('CIDR prefix exceeds the address length.');
        }
        $mask = self::mask(strlen($address), $prefix);
        return [$address & $mask, ($address & $mask) | ~$mask];
    }

    /** Creates a network-order byte mask without architecture-dependent integers. */
    private static function mask(int $bytes, int $prefix): string
    {
        $mask = '';
        for ($byte = 0; $byte < $bytes; $byte++) {
            $bits = max(0, min(8, $prefix - $byte * 8));
            $mask .= chr((0xff << (8 - $bits)) & 0xff);
        }
        return $mask;
    }
}

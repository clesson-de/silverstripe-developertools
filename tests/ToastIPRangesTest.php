<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tests;

use Clesson\Silverstripe\DeveloperTools\Helpers\ToastIPRanges;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;

/**
 * Boundary coverage for IPv4/IPv6 single addresses, networks and inclusive ranges.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class ToastIPRangesTest extends SapphireTest
{
    #[DataProvider('matchesProvider')]
    public function testMatching(string $rules, ?string $ip, bool $expected): void
    {
        self::assertSame($expected, ToastIPRanges::matches($rules, $ip));
    }

    public static function matchesProvider(): array
    {
        return [
            ['', null, true],
            ['192.0.2.1', null, false],
            ['192.0.2.1', 'invalid', false],
            ['192.0.2.1', '192.0.2.1', true],
            ['192.0.2.1', '192.0.2.2', false],
            ['192.0.2.15/24', '192.0.2.0', true],
            ['192.0.2.0/24', '192.0.2.255', true],
            ['192.0.2.0/24', '192.0.3.0', false],
            ['192.0.2.1/32', '192.0.2.2', false],
            ['0.0.0.0/0', '255.255.255.255', true],
            ['0.0.0.0/0', '::1', false],
            ['192.0.2.5 - 192.0.2.10', '192.0.2.5', true],
            ['192.0.2.5-192.0.2.10', '192.0.2.10', true],
            ['192.0.2.5-192.0.2.10', '192.0.2.11', false],
            ['2001:db8::1', '2001:0db8:0:0:0:0:0:1', true],
            ['2001:db8::/32', '2001:db8:ffff:ffff:ffff:ffff:ffff:ffff', true],
            ['2001:db8::/32', '2001:db9::', false],
            ['2001:db8::1/128', '2001:db8::2', false],
            ['::/0', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', true],
            ['::/0', '192.0.2.1', false],
            ['2001:db8::a - 2001:db8::ff', '2001:db8::a', true],
            ['2001:db8::a-2001:db8::ff', '2001:db8::ff', true],
            ['2001:db8::a-2001:db8::ff', '2001:db8::100', false],
            ["192.0.2.1,\n2001:db8::1\r\n198.51.100.0/24", '198.51.100.255', true],
            ["192.0.2.1,\n2001:db8::1", '203.0.113.1', false],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidRulesAreRejected(string $rules): void
    {
        $this->expectException(DomainException::class);
        ToastIPRanges::parse($rules);
    }

    public static function invalidProvider(): array
    {
        return array_map(static fn(string $rule): array => [$rule], [
            'not-an-ip', '192.0.2', '256.0.0.1', '192.0.2.1/33', '::1/129',
            '192.0.2.1/-1', '::1/abc', '::1/64/12', '::1/', '::1/01',
            '192.0.2.10-192.0.2.5', '2001:db8::ff-2001:db8::a',
            '192.0.2.1-::1', '192.0.2.1-', '::1-::2-::3',
            "192.0.2.1,\ninvalid", '::1%eth0', ',,,',
        ]);
    }
}

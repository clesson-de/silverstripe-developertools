<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tests;

use Clesson\Silverstripe\DeveloperTools\Helpers\MonitoringURL;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringHttpClient;
use CurlHandle;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Dev\SapphireTest;
use UnexpectedValueException;

/**
 * Tests destination controls using synthetic DNS only; no live connections.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class MonitoringHttpClientTest extends SapphireTest
{
    /** Normalizes a subdirectory installation without changing its path case. */
    public function testURLNormalization(): void
    {
        self::assertSame('https://customer.org/Subdir', MonitoringURL::create()->normalize('HTTPS://CUSTOMER.ORG:443/Subdir/'));
        self::assertSame('https://8.8.8.8', MonitoringURL::create()->normalize('https://8.8.8.8/'));
        self::assertSame('https://[2606:4700:4700::1111]', MonitoringURL::create()->normalize('https://[2606:4700:4700::1111]/'));
    }

    /** Rejects credentials, unsupported schemes, URL ambiguity and local literals. */
    #[DataProvider('invalidURLs')]
    public function testRejectsURLs(string $url): void
    {
        $this->expectException(DomainException::class);
        MonitoringURL::create()->normalize($url);
    }

    /** Supplies unsafe URL variants without performing DNS requests. */
    public static function invalidURLs(): array
    {
        return array_map(static fn (string $url): array => [$url], [
            'http://customer.org', 'https://user:password@customer.org', 'https://customer.org?key=secret',
            'https://customer.org#fragment', 'https://127.0.0.1', 'https://[::1]', 'https://[::ffff:8.8.8.8]',
            'https://localhost', 'https://host.local', 'https://2130706433', 'https://127.1',
            'https://customer.org/../secret', 'https://customer.org/%2fsecret', "https://customer.org/\r\n",
            'https://customer.org\\@127.0.0.1', 'https://customer.org//other', 'https://customer.org:0',
        ]);
    }

    /** Rejects non-global IPv4 and IPv6, including mapped and transition ranges. */
    #[DataProvider('nonPublicAddresses')]
    public function testRejectsAddresses(string $address): void
    {
        $this->expectException(DomainException::class);
        MonitoringURL::create()->assertPublicAddress($address);
    }

    /** Covers public-looking reserved ranges omitted by some PHP IP filters. */
    public static function nonPublicAddresses(): array
    {
        return array_map(static fn (string $ip): array => [$ip], [
            '0.0.0.0', '10.1.2.3', '100.64.0.1', '127.0.0.2', '169.254.169.254',
            '172.16.0.1', '192.168.1.1', '192.0.0.9', '192.0.2.1', '192.88.99.1',
            '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '255.255.255.255',
            '::', '::1', '::ffff:127.0.0.1', '::ffff:8.8.8.8', 'fc00::1', 'fe80::1',
            'ff02::1', '2001:db8::1', '2001::1', '2002:808:808::1', '3fff::1', '64:ff9b::808:808',
        ]);
    }

    /** An unsafe AAAA answer rejects the entire otherwise-public destination. */
    public function testAllDNSAnswersAreValidated(): void
    {
        $validator = $this->getMockBuilder(MonitoringURL::class)->onlyMethods(['resolve'])->getMock();
        $validator->method('resolve')->willReturn(['8.8.8.8', '::1']);
        Injector::inst()->registerService($validator, MonitoringURL::class);
        $this->expectException(UnexpectedValueException::class);
        MonitoringHttpClient::create()->fetch('https://customer.org', 'synthetic-test-credential');
    }

    /** A development exception is exact-host and environment scoped, not a global bypass. */
    #[DataProvider('developmentDestinations')]
    public function testDevelopmentDestinationPolicy(string $environment, string $host, string $address, bool $allowed): void
    {
        $kernel = Injector::inst()->get(Kernel::class);
        $previous = $kernel->getEnvironment();
        Config::modify()->set(MonitoringURL::class, 'dev_allowed_hosts', ['customer.ddev.site']);
        $validator = $this->getMockBuilder(MonitoringURL::class)->onlyMethods(['resolve'])->getMock();
        $validator->method('resolve')->willReturn([$address]);
        try {
            $kernel->setEnvironment($environment);
            if (!$allowed) {
                $this->expectException(DomainException::class);
            }
            self::assertSame([$address], $validator->publicAddresses($host));
        } finally {
            $kernel->setEnvironment($previous);
        }
    }

    /** Covers development-only private ranges and unsafe or unmatched destinations. */
    public static function developmentDestinations(): array
    {
        return [
            ['dev', 'customer.ddev.site', '172.18.0.6', true],
            ['dev', 'customer.ddev.site', '10.0.0.2', true],
            ['dev', 'customer.ddev.site', '192.168.1.2', true],
            ['dev', 'customer.ddev.site', '127.0.0.1', true],
            ['dev', 'customer.ddev.site', '::1', true],
            ['dev', 'customer.ddev.site', 'fd00::1', true],
            ['dev', 'customer.ddev.site', '8.8.8.8', true],
            ['live', 'customer.ddev.site', '172.18.0.6', false],
            ['test', 'customer.ddev.site', '172.18.0.6', false],
            ['dev', 'other.ddev.site', '172.18.0.6', false],
            ['dev', 'sub.customer.ddev.site', '172.18.0.6', false],
            ['dev', 'customer.ddev.site.evil.org', '172.18.0.6', false],
            ['dev', 'customer.ddev.site', '169.254.169.254', false],
            ['dev', 'customer.ddev.site', 'fe80::1', false],
            ['dev', 'customer.ddev.site', '::ffff:127.0.0.1', false],
            ['dev', 'customer.ddev.site', '0.0.0.0', false],
            ['dev', 'customer.ddev.site', '224.0.0.1', false],
            ['dev', 'customer.ddev.site', 'not-an-address', false],
        ];
    }

    /** Local requests use the same HTTP client after development destination validation. */
    public function testDevelopmentRequestRetainsTransportProtection(): void
    {
        $kernel = Injector::inst()->get(Kernel::class);
        $previous = $kernel->getEnvironment();
        Config::modify()->set(MonitoringURL::class, 'dev_allowed_hosts', ['customer.ddev.site']);
        $validator = $this->getMockBuilder(MonitoringURL::class)->onlyMethods(['resolve'])->getMock();
        $validator->method('resolve')->willReturn(['172.18.0.6']);
        Injector::inst()->registerService($validator, MonitoringURL::class);
        $client = $this->getMockBuilder(MonitoringHttpClient::class)->onlyMethods(['transfer'])->getMock();
        $client->expects($this->once())->method('transfer')->willReturn([true, 200]);
        try {
            $kernel->setEnvironment('dev');
            self::assertSame('', $client->fetch('https://customer.ddev.site', 'synthetic-test-credential'));
        } finally {
            $kernel->setEnvironment($previous);
        }
    }

    /** An allowed local answer never hides another unsafe DNS answer. */
    public function testDevelopmentExceptionValidatesEveryAnswer(): void
    {
        $kernel = Injector::inst()->get(Kernel::class);
        $previous = $kernel->getEnvironment();
        Config::modify()->set(MonitoringURL::class, 'dev_allowed_hosts', ['customer.ddev.site']);
        $validator = $this->getMockBuilder(MonitoringURL::class)->onlyMethods(['resolve'])->getMock();
        $validator->method('resolve')->willReturn(['172.18.0.6', '169.254.169.254']);
        try {
            $kernel->setEnvironment('dev');
            $this->expectException(DomainException::class);
            $validator->publicAddresses('customer.ddev.site');
        } finally {
            $kernel->setEnvironment($previous);
        }
    }

    /** TLS, redirects, timeouts, proxies and the actual curl destination are explicit. */
    public function testPinnedCurlOptions(): void
    {
        $client = new class extends MonitoringHttpClient {
            /** Exposes configuration without executing curl. */
            public function testOptions(): array
            {
                return $this->options('https://customer.org/developer-tools/project-information', 'customer.org', 443, '8.8.8.8', 'synthetic-test-credential');
            }
        };
        $options = $client->testOptions();
        self::assertSame(['customer.org:443:8.8.8.8'], $options[CURLOPT_RESOLVE]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame('', $options[CURLOPT_PROXY]);
        self::assertSame('*', $options[CURLOPT_NOPROXY]);
        self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        self::assertSame(30, $options[CURLOPT_TIMEOUT]);
    }

    /** Credentials cannot inject another HTTP header. */
    public function testRejectsHeaderInjection(): void
    {
        self::assertFalse(MonitoringHttpClient::validToken("token\r\nInjected: value"));
        self::assertFalse(MonitoringHttpClient::validToken(''));
        self::assertFalse(MonitoringHttpClient::validToken(str_repeat('a', 4097)));
    }

    /** Fails closed on timeout, authorization errors, redirects and upstream errors. */
    #[DataProvider('failedTransfers')]
    public function testSafeTransferFailures(bool $success, int $status): void
    {
        $validator = $this->getMockBuilder(MonitoringURL::class)->onlyMethods(['resolve'])->getMock();
        $validator->method('resolve')->willReturn(['8.8.8.8']);
        Injector::inst()->registerService($validator, MonitoringURL::class);
        $client = $this->getMockBuilder(MonitoringHttpClient::class)->onlyMethods(['transfer'])->getMock();
        $client->expects($this->once())->method('transfer')->willReturn([$success, $status]);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Monitoring HTTP request failed.');
        $client->fetch('https://customer.org', 'synthetic-test-credential');
    }

    /** Transport result combinations, with no actual curl execution. */
    public static function failedTransfers(): array
    {
        return [[false, 0], [false, 200], [true, 301], [true, 401], [true, 500]];
    }

    /** Re-resolution rejects a newly private address even after a successful request. */
    public function testDNSRevalidatedOnEveryFetch(): void
    {
        $validator = $this->getMockBuilder(MonitoringURL::class)->onlyMethods(['resolve'])->getMock();
        $validator->expects($this->exactly(2))->method('resolve')->willReturnOnConsecutiveCalls(['8.8.8.8'], ['127.0.0.1']);
        Injector::inst()->registerService($validator, MonitoringURL::class);
        $client = $this->getMockBuilder(MonitoringHttpClient::class)->onlyMethods(['transfer'])->getMock();
        $client->expects($this->once())->method('transfer')->willReturn([true, 200]);
        self::assertSame('', $client->fetch('https://customer.org', 'synthetic-test-credential'));
        $this->expectException(UnexpectedValueException::class);
        $client->fetch('https://customer.org', 'synthetic-test-credential');
    }

    /** Oversized transfers stop before the next byte is appended to memory. */
    public function testBodyLimit(): void
    {
        $client = new class extends MonitoringHttpClient {
            /** Exercises the actual write callback without executing curl. */
            public function exerciseWriter(CurlHandle $handle): array
            {
                $body = '';
                $writer = $this->bodyWriter($body);
                $accepted = $writer($handle, str_repeat('x', 1048576));
                $rejected = $writer($handle, 'x');
                return [$accepted, $rejected, strlen($body)];
            }
        };
        $handle = curl_init();
        try {
            self::assertSame([1048576, 0, 1048576], $client->exerciseWriter($handle));
        } finally {
            curl_close($handle);
        }
    }
}

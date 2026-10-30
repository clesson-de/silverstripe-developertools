<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tests;

use Clesson\Silverstripe\DeveloperTools\Helpers\MonitoringURL;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoringCheck;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReport;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReportRenderer;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringRedirectCheck;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringRedirectClient;
use Closure;
use CurlHandle;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use UnexpectedValueException;

/**
 * Deterministic HTTP redirect assessment and transport protection without live connections.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class MonitoringRedirectTest extends SapphireTest
{
    /** Assesses only the first response and never contacts the Location target. */
    #[DataProvider('responses')]
    public function testAssessment(int $status, array $locations, bool $invalid, string $expected): void
    {
        $client = $this->createMock(MonitoringRedirectClient::class);
        $client->expects($this->once())->method('probe')->with('https://customer.org/Subdir')
            ->willReturn(['status' => $status, 'locations' => $locations, 'invalid' => $invalid]);
        Injector::inst()->registerService($client, MonitoringRedirectClient::class);
        $result = MonitoringRedirectCheck::create()->check('https://customer.org/Subdir');
        self::assertSame($expected, $result['status']);
        self::assertSame(MonitoringRedirectCheck::ID, $result['id']);
        self::assertStringNotContainsString('customer.org', $result['message']);
    }

    /** Includes accepted redirect codes, URL ambiguity and answered non-redirect requests. */
    public static function responses(): array
    {
        $cases = [];
        foreach ([301, 302, 303, 307, 308] as $status) {
            $cases[] = [$status, ['https://www.customer.org/Subdir/?a=1#content'], false, 'ok'];
        }
        foreach ([200, 204, 300, 304, 400, 401, 403, 404, 500] as $status) {
            $cases[] = [$status, ['https://customer.org'], false, 'warning'];
        }
        foreach (['/relative', '//customer.org', 'http://www.customer.org', 'javascript:alert(1)',
            'https://', 'https://user:pass@customer.org', "https://customer.org/\r\nbad",
            'https://customer.org\\@evil.org', 'https://customer.org:0'] as $location) {
            $cases[] = [301, [$location], false, 'warning'];
        }
        return array_merge($cases, [
            [301, [], false, 'warning'],
            [301, ['https://customer.org', 'https://other.org'], false, 'warning'],
            [301, ['https://customer.org'], true, 'warning'],
        ]);
    }

    /** Expected network failures are unknown while unexpected programming errors propagate. */
    public function testFailuresAreNotReportedAsSuccess(): void
    {
        foreach ([new DomainException('Private destination'), new UnexpectedValueException('Timeout')] as $error) {
            $client = $this->createMock(MonitoringRedirectClient::class);
            $client->method('probe')->willThrowException($error);
            Injector::inst()->registerService($client, MonitoringRedirectClient::class);
            self::assertSame('unknown', MonitoringRedirectCheck::create()->check('https://customer.org')['status']);
        }
        $client = $this->createMock(MonitoringRedirectClient::class);
        $client->method('probe')->willThrowException(new RuntimeException('Programming error'));
        Injector::inst()->registerService($client, MonitoringRedirectClient::class);
        $this->expectException(RuntimeException::class);
        MonitoringRedirectCheck::create()->check('https://customer.org');
    }

    /** Exercises real header parsing, port/path derivation and the stop-before-body callback. */
    public function testHeaderProbeAndTransportOptions(): void
    {
        $validator = $this->getMockBuilder(MonitoringURL::class)->onlyMethods(['resolve'])->getMock();
        $validator->expects($this->once())->method('resolve')->with('customer.org')->willReturn(['2606:4700:4700::1111']);
        Injector::inst()->registerService($validator, MonitoringURL::class);
        $client = $this->clientWithHeaders([
            "HTTP/1.1 103 Early Hints\r\n", "Link: </style.css>\r\n", "\r\n",
            "HTTP/1.1 301 Moved\r\n", "Location: https://www.customer.org/Subdir\r\n", "\r\n",
        ]);
        $response = $client->probe('https://customer.org:8443/Subdir/');
        self::assertTrue($response['complete']);
        self::assertSame(301, $response['status']);
        self::assertSame(['https://www.customer.org/Subdir'], $response['locations']);
        $options = $client->capturedOptions;
        self::assertSame('http://customer.org/Subdir', $options[CURLOPT_URL]);
        self::assertSame(['customer.org:80:[2606:4700:4700::1111]'], $options[CURLOPT_RESOLVE]);
        self::assertSame(['Accept: */*'], $options[CURLOPT_HTTPHEADER]);
        self::assertSame(CURLPROTO_HTTP, $options[CURLOPT_PROTOCOLS]);
        self::assertSame(3, $options[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(5, $options[CURLOPT_TIMEOUT]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $options[CURLOPT_MAXREDIRS]);
        self::assertSame('', $options[CURLOPT_PROXY]);
        self::assertSame('*', $options[CURLOPT_NOPROXY]);
        self::assertSame(CURL_NETRC_IGNORED, $options[CURLOPT_NETRC]);
        foreach ([CURLOPT_USERPWD, CURLOPT_COOKIE, CURLOPT_COOKIEFILE, CURLOPT_COOKIEJAR] as $option) {
            self::assertArrayNotHasKey($option, $options);
        }
        self::assertSame(0, ($options[CURLOPT_WRITEFUNCTION])(curl_init(), 'body is never retained'));
    }

    /** Uses public literal IPs so these parser cases need no DNS at all. */
    #[DataProvider('headerCases')]
    public function testHeaderBoundsAndAmbiguity(array $lines, ?bool $invalid, array $locations): void
    {
        $client = $this->clientWithHeaders($lines);
        if ($invalid === null) {
            $this->expectException(UnexpectedValueException::class);
        }
        $response = $client->probe('https://8.8.8.8');
        self::assertSame($invalid, $response['invalid']);
        self::assertSame($locations, $response['locations']);
        self::assertSame(['8.8.8.8:80:8.8.8.8'], $client->capturedOptions[CURLOPT_RESOLVE]);
    }

    /** Covers malformed, conflicting, incomplete, oversized and missing response headers. */
    public static function headerCases(): array
    {
        return [
            [["HTTP/1.1 301 Moved\r\n", "Location: https://customer.org\r\n", " folded\r\n", "\r\n"], true, ['https://customer.org']],
            [["HTTP/1.1 301 Moved\r\n", "Location: https://customer.org\r\n", "Location: https://other.org\r\n", "\r\n"], false, ['https://customer.org', 'https://other.org']],
            [["HTTP/1.1 200 OK\r\n", "\r\n"], false, []],
            [["HTTP/1.1 301 Moved\r\n", "Malformed\r\n", "\r\n"], true, []],
            [["HTTP/1.1 301 Moved\r\n"], null, []],
            [["HTTP/1.1 301 Moved\r\n", 'X-Large: ' . str_repeat('a', 16384)], null, []],
            [[], null, []],
        ];
    }

    /** Revalidates all DNS answers before the transfer, including private/metadata targets. */
    public function testUnsafeDNSNeverConnects(): void
    {
        $validator = $this->getMockBuilder(MonitoringURL::class)->onlyMethods(['resolve'])->getMock();
        $validator->method('resolve')->willReturn(['8.8.8.8', '169.254.169.254']);
        Injector::inst()->registerService($validator, MonitoringURL::class);
        $client = $this->getMockBuilder(MonitoringRedirectClient::class)->onlyMethods(['transfer'])->getMock();
        $client->expects($this->never())->method('transfer');
        $this->expectException(DomainException::class);
        $client->probe('https://customer.org');
    }

    /** A separate central result preserves legacy behaviour and the maximum remote report size. */
    public function testCombinedReportsAndLegacyHistory(): void
    {
        self::assertSame([], MonitoringCheck::create()->getHealthChecks());
        $remote = [];
        for ($index = 0; $index < HealthReport::MAX_CHECKS; $index++) {
            $remote[] = HealthReport::create()->result('remote.' . $index, 'Remote check', 'ok', 'OK');
        }
        $central = HealthReport::create()->result(MonitoringRedirectCheck::ID, 'HTTP to HTTPS redirect', 'warning', 'No redirect.');
        $check = MonitoringCheck::create([
            'HealthResults' => json_encode($remote), 'HttpRedirectResult' => json_encode([$central]),
        ]);
        self::assertCount(51, $check->getHealthChecks());
        self::assertSame('warning', $check->getHealthStatus());
        self::assertStringContainsString('No redirect.', HealthReportRenderer::create()->render($check->getHealthChecks()));
        $this->expectException(DomainException::class);
        HealthReport::create()->normalize($check->getHealthChecks());
    }

    /** Synthetic transport feeds the production parser without making any connections. */
    private function clientWithHeaders(array $lines): MonitoringRedirectClient
    {
        return new class ($lines) extends MonitoringRedirectClient {
            /** Captured request configuration. */
            public array $capturedOptions = [];
            /** Actual production callback. */
            private Closure $reader;
            /** Retains only synthetic response lines. */
            public function __construct(private array $lines) {}
            /** Captures the real configuration for assertions. */
            protected function options(string $url, string $host, string $address): array
            {
                return $this->capturedOptions = parent::options($url, $host, $address);
            }
            /** Captures the actual parser instead of replacing its behaviour. */
            protected function headerReader(array &$response, int &$bytes): Closure
            {
                return $this->reader = parent::headerReader($response, $bytes);
            }
            /** Stops feeding headers exactly when cURL would stop the transfer. */
            protected function transfer(CurlHandle $handle): void
            {
                foreach ($this->lines as $line) {
                    if (($this->reader)($handle, $line) === 0) {
                        break;
                    }
                }
            }
        };
    }
}

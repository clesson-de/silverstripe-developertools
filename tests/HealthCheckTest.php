<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tests;

use Clesson\Silverstripe\DeveloperTools\HealthChecks\HealthCheck;
use Clesson\Silverstripe\DeveloperTools\HealthChecks\NodeHealthCheck;
use Clesson\Silverstripe\DeveloperTools\HealthChecks\RuntimeHealthCheck;
use Clesson\Silverstripe\DeveloperTools\Helpers\NodeRequirementFinder;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoringCheck;
use Clesson\Silverstripe\DeveloperTools\Services\HealthCheckService;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReport;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReportRenderer;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringResponse;
use Clesson\Silverstripe\DeveloperTools\Services\NodeSupportService;
use Clesson\Silverstripe\DeveloperTools\Services\ReleaseMetadataClient;
use DomainException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use SilverStripe\Core\Config\Config;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Covers safe extensibility, protocol validation and official Node support dates.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class HealthCheckTest extends SapphireTest
{
    /** Runtime fixtures live outside the scanned project and are removed after each test. */
    private ?string $nodeFixture = null;

    /** Creates only known paths so cleanup never traverses arbitrary directories. */
    private function nodeFixture(): string
    {
        if ($this->nodeFixture !== null) {
            return $this->nodeFixture;
        }
        $this->nodeFixture = sys_get_temp_dir() . '/developertools-node-' . bin2hex(random_bytes(8));
        mkdir($this->nodeFixture);
        foreach (['empty', 'themes/example', 'app/client', 'app/vendor/ignored', 'assets/ignored'] as $directory) {
            mkdir($this->nodeFixture . '/' . $directory, 0700, true);
        }
        foreach (['.nvmrc' => '18', 'app/client/.nvmrc' => '24', 'themes/example/.nvmrc' => '22',
            'app/vendor/ignored/.nvmrc' => '1', 'assets/ignored/.nvmrc' => '1'] as $path => $version) {
            file_put_contents($this->nodeFixture . '/' . $path, $version);
        }
        return $this->nodeFixture;
    }

    /** Removes only this test's explicitly created files and directories. */
    protected function tearDown(): void
    {
        if ($this->nodeFixture !== null) {
            foreach (['.nvmrc', 'app/client/.nvmrc', 'themes/example/.nvmrc', 'app/vendor/ignored/.nvmrc',
                'assets/ignored/.nvmrc'] as $path) {
                unlink($this->nodeFixture . '/' . $path);
            }
            foreach (['themes/example', 'themes', 'app/client', 'app/vendor/ignored', 'app/vendor',
                'app', 'assets/ignored', 'assets', 'empty'] as $directory) {
                rmdir($this->nodeFixture . '/' . $directory);
            }
            rmdir($this->nodeFixture);
        }
        parent::tearDown();
    }

    /** Minimal protocol inventory independent of PHPUnit class loading order. */
    private const INVENTORY = [
        'php_version' => '8.3.0', 'silverstripe_version' => '6.1.0',
        'database_version' => '10.11.0-MariaDB',
        'composer_packages' => [],
    ];

    /** Runs a project implementation through the real collector, without network requests. */
    public function testProjectCheckRegistration(): void
    {
        $check = $this->createMock(HealthCheck::class);
        $check->expects($this->once())->method('run')->willReturn([
            HealthReport::singleton()->result('project.import', 'Import', 'warning', 'Import is late.'),
        ]);
        Config::modify()->set(HealthCheckService::class, 'checks', ['custom' => HealthCheck::class]);
        Injector::inst()->registerService($check, HealthCheck::class);
        $results = HealthCheckService::create()->collect();
        self::assertSame('project.import', $results[0]['id']);
        self::assertSame('warning', $results[0]['status']);
        self::assertStringContainsString('Import is late.', HealthReportRenderer::create()->render($results));
    }

    /** Incorrect registrations fail explicitly rather than returning success-shaped results. */
    public function testInvalidRegistrationFails(): void
    {
        Config::modify()->set(HealthCheckService::class, 'checks', ['invalid' => HealthReport::class]);
        $this->expectException(LogicException::class);
        HealthCheckService::create()->collect();
    }

    /** Validates the new envelope while keeping legacy inventory snapshots identical. */
    public function testVersionedResponseAndLegacyCompatibility(): void
    {
        $inventory = self::INVENTORY;
        $legacy = MonitoringResponse::create()->decode(json_encode($inventory));
        $health = [HealthReport::singleton()->result('custom.test', 'Test', 'error', 'Needs attention.')];
        $modern = MonitoringResponse::create()->decode(json_encode([
            'schema_version' => 2, 'inventory' => $inventory, 'health_checks' => $health,
        ]));
        self::assertSame($legacy['inventory'], $modern['inventory']);
        self::assertSame([], $legacy['health_checks']);
        self::assertSame($health, $modern['health_checks']);
    }

    /** Malformed or unsupported payloads are never accepted as healthy reports. */
    #[DataProvider('invalidResponses')]
    public function testInvalidResponse(string $body): void
    {
        $this->expectException(DomainException::class);
        MonitoringResponse::create()->decode($body);
    }

    /** Supplies unsupported versions, wrong result types and unknown envelope fields. */
    public static function invalidResponses(): array
    {
        $inventory = self::INVENTORY;
        return [
            ['[]'], ['{'],
            [json_encode(['schema_version' => 3, 'inventory' => $inventory, 'health_checks' => []])],
            [json_encode(['schema_version' => 2, 'inventory' => $inventory, 'health_checks' => new stdClass()])],
            [json_encode(['schema_version' => 2, 'inventory' => $inventory, 'health_checks' => [], 'extra' => true])],
        ];
    }

    /** Report data must have safe IDs, finite lengths, valid dates and known states. */
    #[DataProvider('invalidResults')]
    public function testInvalidHealthResults(array $overrides): void
    {
        $row = ['id' => 'test', 'title' => 'Test', 'status' => 'ok', 'message' => '', 'checked_at' => '2026-10-26T12:00:00Z'];
        $this->expectException(DomainException::class);
        HealthReport::create()->normalize([array_replace($row, $overrides)]);
    }

    /** Covers invalid fields and output unsafe to store or display. */
    public static function invalidResults(): array
    {
        return [
            [['id' => '../secret']], [['status' => 'healthy']], [['title' => '']],
            [['message' => '<script>']], [['message' => "unsafe\nvalue"]],
            [['message' => str_repeat('a', 1001)]], [['checked_at' => '2026-02-30T12:00:00Z']],
            [['extra' => 'value']], [['checked_at' => 'now']], [['status' => false]],
        ];
    }

    /** Duplicate identifiers cannot mask a failing result. */
    public function testDuplicateResultsRejected(): void
    {
        $row = HealthReport::create()->result('test', 'Test', 'ok', '');
        $this->expectException(DomainException::class);
        HealthReport::create()->normalize([$row, $row]);
    }

    /** Source results are cached; numeric EOL and supported lines remain distinct. */
    public function testNodeSupportScheduleAndCache(): void
    {
        $this->cache();
        $client = $this->createMock(ReleaseMetadataClient::class);
        $client->expects($this->once())->method('fetch')->with(NodeSupportService::SOURCE)->willReturn([
            'v18' => ['start' => '2022-01-01', 'end' => '2025-04-30'],
            'v99' => ['start' => '2020-01-01', 'end' => '2099-01-01'],
            'v100' => ['start' => '2098-01-01', 'end' => '2099-01-01'],
            'v101' => ['start' => '2020-01-01', 'end' => '2099-02-30'],
        ]);
        Injector::inst()->registerService($client, ReleaseMetadataClient::class);
        $service = NodeSupportService::create();
        self::assertSame('warning', $service->assess('v18.0.0')[0]);
        self::assertSame('ok', $service->assess('99')[0]);
        self::assertSame('unknown', $service->assess('100')[0]);
        self::assertSame('unknown', $service->assess('101')[0]);
        self::assertSame('unknown', $service->assess('lts/*')[0]);
        self::assertSame('unknown', $service->assess('node')[0]);
    }

    /** A source failure is unknown and is cached instead of repeatedly fetching. */
    public function testNodeSourceFailure(): void
    {
        $this->cache();
        $client = $this->createMock(ReleaseMetadataClient::class);
        $client->expects($this->once())->method('fetch')->willThrowException(new RuntimeException('Synthetic failure'));
        Injector::inst()->registerService($client, ReleaseMetadataClient::class);
        self::assertSame('unknown', NodeSupportService::create()->assess('18')[0]);
        self::assertSame('unknown', NodeSupportService::create()->assess('22')[0]);
    }

    /** Projects without Node requirement files omit this optional check entirely. */
    public function testUnavailableNodeFiles(): void
    {
        Config::modify()->set(Director::class, 'alternate_base_folder', $this->nodeFixture() . '/empty');
        $service = $this->createMock(NodeSupportService::class);
        $service->expects($this->never())->method('assess');
        Injector::inst()->registerService($service, NodeSupportService::class);
        self::assertSame([], (new NodeHealthCheck())->run(microtime(true) + 1));
    }

    /** All discovered builds are evaluated independently, excluding assets and vendor trees. */
    public function testConfiguredNodeRequirement(): void
    {
        Config::modify()->set(Director::class, 'alternate_base_folder', $this->nodeFixture());
        $service = $this->createMock(NodeSupportService::class);
        $versions = [];
        $service->expects($this->exactly(3))->method('assess')->willReturnCallback(
            static function (string $version) use (&$versions): array {
                $versions[] = $version;
                return [$version === '18' ? 'warning' : 'ok', 'Support evaluated.'];
            }
        );
        Injector::inst()->registerService($service, NodeSupportService::class);
        $rows = (new NodeHealthCheck())->run(microtime(true) + 10);
        self::assertCount(3, $rows);
        self::assertSame(['18', '24', '22'], $versions);
        $row = $rows[0];
        self::assertSame('node.' . substr(hash('sha256', '.nvmrc'), 0, 24), $row['id']);
        self::assertStringContainsString(': 18', $row['title']);
        self::assertSame('warning', $row['status']);
        self::assertSame('unknown', (new NodeHealthCheck())->run(0)[0]['status']);
    }

    /** Deadline or unreadable-root failures are explicit, not mistaken for no Node builds. */
    public function testIncompleteNodeDiscovery(): void
    {
        $finder = NodeRequirementFinder::create();
        self::assertFalse($finder->find($this->nodeFixture(), 0)['complete']);
        self::assertFalse($finder->find($this->nodeFixture() . '/not-present', microtime(true) + 1)['complete']);
        $result = $finder->find($this->nodeFixture(), microtime(true) + 1);
        self::assertTrue($result['complete']);
        self::assertSame(['.nvmrc', 'app/client/.nvmrc', 'themes/example/.nvmrc'], $result['files']);
    }

    /** Symlink aliases cannot bypass excluded directory trees. */
    public function testNodeDiscoverySkipsSymlinks(): void
    {
        $root = $this->nodeFixture();
        symlink($root . '/assets/ignored', $root . '/linked-build');
        symlink($root . '/assets/ignored/.nvmrc', $root . '/empty/.nvmrc');
        try {
            $result = NodeRequirementFinder::create()->find($root, microtime(true) + 1);
            self::assertTrue($result['complete']);
            self::assertSame(['.nvmrc', 'app/client/.nvmrc', 'themes/example/.nvmrc'], $result['files']);
        } finally {
            unlink($root . '/linked-build');
            unlink($root . '/empty/.nvmrc');
        }
    }

    /** More than twenty independent builds result in an explicit incomplete search. */
    public function testNodeDiscoveryFileLimit(): void
    {
        $root = $this->nodeFixture();
        try {
            for ($index = 0; $index < 21; $index++) {
                mkdir($root . '/build-' . $index);
                file_put_contents($root . '/build-' . $index . '/.nvmrc', '22');
            }
            $result = NodeRequirementFinder::create()->find($root, microtime(true) + 1);
            self::assertFalse($result['complete']);
            self::assertCount(NodeRequirementFinder::MAX_FILES, $result['files']);
        } finally {
            for ($index = 0; $index < 21; $index++) {
                unlink($root . '/build-' . $index . '/.nvmrc');
                rmdir($root . '/build-' . $index);
            }
        }
    }

    /** One error wins even when all other checks are successful. */
    public function testCriticalHealthResultIsNotDiluted(): void
    {
        $results = [];
        foreach (['ok', 'ok', 'warning', 'error', 'unknown'] as $index => $status) {
            $results[] = HealthReport::create()->result('test.' . $index, 'Test', $status, '');
        }
        $check = MonitoringCheck::create(['HealthResults' => json_encode($results)]);
        self::assertSame('error', $check->getHealthStatus());
        self::assertSame('unknown', MonitoringCheck::create()->getHealthStatus());
    }

    /** CLI checks explicitly avoid claiming the external TLS setup has been verified. */
    public function testLocalRuntimeReport(): void
    {
        $results = HealthReport::create()->normalize((new RuntimeHealthCheck())->run(microtime(true) + 1));
        $rows = array_column($results, null, 'id');
        self::assertSame('unknown', $rows['runtime.https']['status']);
        self::assertStringContainsString('not HTTP', $rows['runtime.webroot']['message']);
        self::assertCount(3, $rows);
    }

    /** Installs isolated in-memory provider metadata. */
    private function cache(): void
    {
        Injector::inst()->registerService(new Psr16Cache(new ArrayAdapter()), CacheInterface::class . '.DeveloperToolsReleases');
    }
}

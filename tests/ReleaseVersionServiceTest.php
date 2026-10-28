<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tests;

use Clesson\Silverstripe\DeveloperTools\Services\ReleaseMetadataClient;
use Clesson\Silverstripe\DeveloperTools\Services\ReleaseUnavailableException;
use Clesson\Silverstripe\DeveloperTools\Services\ReleaseVersionService;
use Clesson\Silverstripe\DeveloperTools\Services\StableReleaseProvider;
use Psr\SimpleCache\CacheInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Verifies stable release selection and truthful cache-only assessments.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class ReleaseVersionServiceTest extends SapphireTest
{
    /** Deterministic inventory; CMS is deliberately absent. */
    private const INVENTORY = [
        'php_version' => '8.3.0', 'silverstripe_version' => '6.1.0',
        'database_version' => '10.11.0-MariaDB', 'database_product' => 'mariadb',
        'composer_packages' => [
            ['name' => 'vendor/library', 'version' => 'v1.0.0'],
            ['name' => 'vendor/development', 'version' => 'dev-main'],
        ],
    ];

    private CacheInterface $cache;

    /** @inheritdoc */
    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new Psr16Cache(new ArrayAdapter());
        Injector::inst()->registerService($this->cache, CacheInterface::class . '.DeveloperToolsReleases');
    }

    /** Exercises the strict majority threshold, unknowns and duplicate CMS display rows. */
    #[DataProvider('actionRequiredCases')]
    public function testActionRequired(array $statuses, ?bool $expected): void
    {
        $service = $this->getMockBuilder(ReleaseVersionService::class)->onlyMethods(['assess'])->getMock();
        $rows = [];
        foreach ($statuses as $key => $status) {
            $rows[] = ['Key' => str_replace('-duplicate', '', $key), 'Status' => $status];
        }
        $service->expects($this->once())->method('assess')->with(self::INVENTORY)->willReturn($rows);
        self::assertSame($expected, $service->actionRequired(self::INVENTORY));
    }

    /** Supplies ratios below, equal to and above half, plus entirely unassessable inventories. */
    public static function actionRequiredCases(): array
    {
        return [
            'empty' => [[], null],
            'unknown and failed' => [['a' => 'unknown', 'b' => 'failed'], null],
            'all current' => [['a' => 'current', 'b' => 'current'], false],
            'one of three' => [['a' => 'update', 'b' => 'current', 'c' => 'current'], false],
            'exactly half' => [['a' => 'update', 'b' => 'current'], false],
            'two of three' => [['a' => 'update', 'b' => 'update', 'c' => 'current'], true],
            'all outdated' => [['a' => 'update'], true],
            'unknowns excluded' => [['a' => 'update', 'b' => 'unknown', 'c' => 'failed'], true],
            'duplicate cms excluded' => [['cms' => 'update', 'cms-duplicate' => 'update', 'php' => 'current'], false],
        ];
    }

    /** Ignores previews and sorts versions numerically rather than lexically. */
    public function testStableSelection(): void
    {
        self::assertSame('v2.10.0', StableReleaseProvider::create()->newest(['2.9.0', '3.0.0-rc1', 'dev-main', 'v2.10.0']));
        $this->expectException(ReleaseUnavailableException::class);
        StableReleaseProvider::create()->newest(['dev-main', '2.0.0-beta1']);
    }

    /** Uses cached data for rendering and reuses it for unchanged future checks. */
    public function testCacheAndComparison(): void
    {
        $provider = $this->createMock(StableReleaseProvider::class);
        $provider->method('source')->willReturn('https://source.example/releases');
        $provider->expects($this->exactly(3))->method('latest')->willReturnMap([
            ['php', '8.5.0'], ['mariadb', '11.4.0'], ['vendor/library', 'v1.0.0'],
        ]);
        Injector::inst()->registerService($provider, StableReleaseProvider::class);
        $service = ReleaseVersionService::create();
        $service->refresh(self::INVENTORY);
        $service->refresh(self::INVENTORY);
        $rows = $service->assess(self::INVENTORY);
        self::assertSame(['update', 'unknown', 'update', 'current', 'unknown'], array_column($rows, 'Status'));
        self::assertSame('Silverstripe CMS', $rows[1]['Item']);
        self::assertSame('', $rows[1]['Current']);
    }

    /** An unavailable source never makes an unchanged installed version appear current. */
    public function testFailureAndExpiredMetadata(): void
    {
        $provider = $this->createMock(StableReleaseProvider::class);
        $provider->method('source')->willReturn('https://source.example/releases');
        $provider->method('latest')->willThrowException(new RuntimeException('Release source request failed.'));
        Injector::inst()->registerService($provider, StableReleaseProvider::class);
        $service = ReleaseVersionService::create();
        $service->refresh(self::INVENTORY);
        self::assertSame('failed', $service->assess(self::INVENTORY)[0]['Status']);
        $this->cache->set(hash('sha256', 'php'), [
            'version' => '8.3.0', 'error' => '', 'checked_at' => time() - 30000,
            'retry_at' => time() - 1, 'source' => 'https://source.example/releases',
        ]);
        self::assertSame('failed', $service->assess(self::INVENTORY)[0]['Status']);
    }

    /** Private or unavailable public packages are explicitly not assessable. */
    public function testUnknownPublicPackage(): void
    {
        $provider = $this->createMock(StableReleaseProvider::class);
        $provider->method('source')->willReturn('https://source.example/releases');
        $provider->method('latest')->willThrowException(new ReleaseUnavailableException('No public release metadata is available.'));
        Injector::inst()->registerService($provider, StableReleaseProvider::class);
        $service = ReleaseVersionService::create();
        $service->refresh(self::INVENTORY);
        self::assertSame('unknown', $service->assess(self::INVENTORY)[3]['Status']);
    }

    /** Reads MariaDB's explicitly stable branch before selecting its latest patch. */
    public function testMariaDbStableBranch(): void
    {
        $client = $this->createMock(ReleaseMetadataClient::class);
        $client->expects($this->exactly(2))->method('fetch')->willReturnMap([
            ['https://downloads.mariadb.org/rest-api/mariadb/', ['major_releases' => [
                ['release_id' => '13.1', 'release_status' => 'RC'],
                ['release_id' => '13.0', 'release_status' => 'Stable'],
                ['release_id' => '11.4', 'release_status' => 'Stable'],
            ]]],
            ['https://downloads.mariadb.org/rest-api/mariadb/13.0/', ['releases' => ['13.0.1' => [], '13.0.2' => []]]],
        ]);
        Injector::inst()->registerService($client, ReleaseMetadataClient::class);
        self::assertSame('13.0.2', StableReleaseProvider::create()->latest('mariadb'));
    }

    /** Excludes similarly named cluster and preview tags from Oracle server releases. */
    public function testMysqlTagsExcludeClusterAndPreviews(): void
    {
        $client = $this->createMock(ReleaseMetadataClient::class);
        $client->expects($this->once())->method('fetch')->willReturn([
            ['ref' => 'refs/tags/mysql-cluster-99.0.0'],
            ['ref' => 'refs/tags/mysql-10.0.0-rc1'],
            ['ref' => 'refs/tags/mysql-9.5.0'],
            ['ref' => 'refs/tags/mysql-8.4.0'],
        ]);
        Injector::inst()->registerService($client, ReleaseMetadataClient::class);
        self::assertSame('9.5.0', StableReleaseProvider::create()->latest('mysql'));
    }

    /** Invalid package keys cannot redirect release requests to arbitrary hosts. */
    public function testInvalidPackageSource(): void
    {
        $this->expectException(RuntimeException::class);
        StableReleaseProvider::create()->source('https://private.example/secrets');
    }
}

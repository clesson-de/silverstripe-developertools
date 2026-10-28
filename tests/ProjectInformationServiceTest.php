<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tests;

use Clesson\Silverstripe\DeveloperTools\Services\ProjectInformationService;
use Composer\InstalledVersions;
use RuntimeException;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\VersionProvider;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\Connect\Database;
use SilverStripe\ORM\Connect\NullDatabase;
use SilverStripe\ORM\DB;

/**
 * Verifies version sources and explicit failures without querying project data.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class ProjectInformationServiceTest extends SapphireTest
{
    private Database $previousConnection;

    /** @inheritdoc */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = DB::get_conn(DB::CONN_PRIMARY);
    }

    /** @inheritdoc */
    protected function tearDown(): void
    {
        DB::set_conn($this->previousConnection, DB::CONN_PRIMARY);
        parent::tearDown();
    }

    /**
     * Uses the current runtime, installed framework and database driver versions.
     */
    public function testVersionSources(): void
    {
        $this->mockDatabaseVersion('10.11.0-MariaDB');
        self::assertSame([
            'php_version' => PHP_VERSION,
            'silverstripe_version' => InstalledVersions::getPrettyVersion('silverstripe/framework'),
            'database_version' => '10.11.0-MariaDB',
        ], ProjectInformationService::create()->getInformation());
    }

    /**
     * Returns sorted installed dependencies with only package names and versions.
     */
    public function testComposerInventory(): void
    {
        $packages = ProjectInformationService::create()->getComposerPackages();
        $names = array_column($packages, 'name');
        $sorted = $names;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $names);
        self::assertNotContains(InstalledVersions::getRootPackage()['name'], $names);
        self::assertContains('silverstripe/framework', $names);
        foreach ($packages as $package) {
            self::assertSame(['name', 'version'], array_keys($package));
            self::assertSame(InstalledVersions::getPrettyVersion($package['name']), $package['version']);
            self::assertNotNull($package['version']);
        }
        foreach (InstalledVersions::getInstalledPackages() as $name) {
            if (InstalledVersions::getPrettyVersion($name) === null) {
                self::assertNotContains($name, $names);
            }
        }
    }

    /**
     * Does not report an empty database version as a successful lookup.
     */
    public function testMissingDatabaseVersionFails(): void
    {
        $this->mockDatabaseVersion('');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The database server version could not be determined.');
        ProjectInformationService::create()->getInformation();
    }

    /**
     * Does not report an empty framework version as a successful lookup.
     */
    public function testMissingFrameworkVersionFails(): void
    {
        $provider = $this->createMock(VersionProvider::class);
        $provider->expects($this->once())->method('getModuleVersion')
            ->with('silverstripe/framework')->willReturn('');
        Injector::inst()->registerService($provider, VersionProvider::class);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The installed Silverstripe framework version could not be determined.');
        ProjectInformationService::create()->getInformation();
    }

    /**
     * Supplies a database driver version without opening a database connection.
     */
    private function mockDatabaseVersion(string $version): void
    {
        $connection = $this->createMock(NullDatabase::class);
        $connection->expects($this->once())->method('getVersion')->willReturn($version);
        DB::set_conn($connection, DB::CONN_PRIMARY);
    }
}

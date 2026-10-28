<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Constants\ProjectInformationField;
use Composer\InstalledVersions;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Manifest\VersionProvider;
use SilverStripe\ORM\DB;

/**
 * Collects runtime versions without exposing credentials or server configuration.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class ProjectInformationService
{
    use Injectable;

    /**
     * Returns the PHP runtime, installed framework and database server versions.
     *
     * @return array<string, string>
     */
    public function getInformation(): array
    {
        return [
            ProjectInformationField::PHP_VERSION => PHP_VERSION,
            ProjectInformationField::SILVERSTRIPE_VERSION => $this->getSilverstripeVersion(),
            ProjectInformationField::DATABASE_VERSION => $this->getDatabaseVersion(),
        ];
    }

    /**
     * Lists installed dependencies, excluding the root project and virtual packages.
     *
     * @return list<array{name: string, version: string}>
     */
    public function getComposerPackages(): array
    {
        $names = InstalledVersions::getInstalledPackages();
        sort($names, SORT_STRING);
        $packages = [];
        $rootName = InstalledVersions::getRootPackage()['name'];
        foreach ($names as $name) {
            $version = InstalledVersions::getPrettyVersion($name);
            if ($name !== $rootName && $version !== null) {
                $packages[] = ['name' => $name, 'version' => $version];
            }
        }
        return $packages;
    }

    /**
     * Identifies the database product rather than comparing unrelated server families.
     */
    public function getDatabaseProduct(): string
    {
        $version = $this->getDatabaseVersion();
        if (stripos($version, 'mariadb') !== false) {
            return 'mariadb';
        }
        $connection = DB::get_conn();
        $server = $connection->getDatabaseServer();
        if ($server === 'mysql') {
            $comment = (string) DB::query('SELECT @@version_comment')->value();
            return stripos($comment, 'mysql') !== false ? 'mysql' : 'unknown';
        }
        return 'unknown';
    }

    /**
     * Reads the installed framework version rather than a dependency constraint.
     */
    private function getSilverstripeVersion(): string
    {
        $version = VersionProvider::singleton()->getModuleVersion('silverstripe/framework');
        if ($version === '') {
            throw new RuntimeException('The installed Silverstripe framework version could not be determined.');
        }
        return $version;
    }

    /**
     * Reads the database server version through the configured database driver.
     */
    private function getDatabaseVersion(): string
    {
        $connection = DB::get_conn();
        if ($connection === null) {
            throw new RuntimeException('No database connection is configured for project information.');
        }
        $version = (string) $connection->getVersion();
        if ($version === '') {
            throw new RuntimeException('The database server version could not be determined.');
        }
        return $version;
    }
}

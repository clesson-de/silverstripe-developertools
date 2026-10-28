<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Composer\Semver\VersionParser;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;

/**
 * Resolves stable releases from official product metadata and Packagist.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class StableReleaseProvider
{
    use Injectable;

    /**
     * Returns the fixed source URL for a known product or Composer package.
     */
    public function source(string $key): string
    {
        return match ($key) {
            'php' => 'https://www.php.net/releases/index.php?json',
            'mariadb' => 'https://downloads.mariadb.org/rest-api/mariadb/',
            'mysql' => 'https://api.github.com/repos/mysql/mysql-server/git/matching-refs/tags/mysql-',
            default => $this->packageSource($key),
        };
    }

    /**
     * Returns the newest stable version, preserving the provider's readable value.
     */
    public function latest(string $key): string
    {
        $data = ReleaseMetadataClient::singleton()->fetch($this->source($key));
        $versions = match ($key) {
            'php' => array_column($data, 'version'),
            'mysql' => $this->mysqlVersions($data),
            'mariadb' => $this->mariaVersions($data),
            default => array_column($this->packageVersions($data, $key), 'version'),
        };
        return $this->newest($versions);
    }

    /** Rejects malformed public package metadata as a source failure. */
    private function packageVersions(array $data, string $key): array
    {
        $versions = $data['packages'][$key] ?? null;
        if (!is_array($versions)) {
            throw new RuntimeException('Release source returned invalid package metadata.');
        }
        return $versions;
    }

    /**
     * Builds a public package URL only for valid Composer dependency names.
     */
    private function packageSource(string $key): string
    {
        if (preg_match('~^[a-z0-9]+(?:[_.-][a-z0-9]+)*/[a-z0-9]+(?:[_.-][a-z0-9]+)*$~D', $key) !== 1) {
            throw new RuntimeException('Unsupported release source.');
        }
        return 'https://repo.packagist.org/p2/' . $key . '.json';
    }

    /**
     * Selects numeric server release tags, excluding cluster and prerelease tags.
     */
    private function mysqlVersions(array $data): array
    {
        $versions = [];
        foreach ($data as $tag) {
            if (is_array($tag) && is_string($tag['ref'] ?? null)
                && preg_match('~^refs/tags/mysql-(\d+\.\d+\.\d+)$~D', $tag['ref'], $match) === 1) {
                $versions[] = $match[1];
            }
        }
        return $versions;
    }

    /**
     * Reads release entries only from the newest branch marked Stable by MariaDB.
     */
    private function mariaVersions(array $data): array
    {
        $branch = $this->newest($this->mariaStableBranches($data));
        $url = $this->source('mariadb') . rawurlencode($branch) . '/';
        $releases = ReleaseMetadataClient::singleton()->fetch($url);
        if (!is_array($releases['releases'] ?? null)) {
            throw new RuntimeException('Release source returned invalid database releases.');
        }
        return array_keys($releases['releases']);
    }

    /** Separates stable MariaDB branches from preview and RC product series. */
    private function mariaStableBranches(array $data): array
    {
        $branches = [];
        $releases = $data['major_releases'] ?? null;
        if (!is_array($releases)) {
            throw new RuntimeException('Release source returned invalid database metadata.');
        }
        foreach ($releases as $branch) {
            if (is_array($branch) && ($branch['release_status'] ?? '') === 'Stable' && is_string($branch['release_id'] ?? null)) {
                $branches[] = $branch['release_id'];
            }
        }
        return $branches;
    }

    /**
     * Filters prereleases and selects by version ordering, not response ordering.
     */
    public function newest(array $versions): string
    {
        $latest = null;
        foreach ($versions as $version) {
            if (is_string($version) && preg_match('/^v?\d+\.\d+(?:\.\d+){0,2}$/D', $version) === 1) {
                if (VersionParser::parseStability($version) === 'stable'
                    && ($latest === null || version_compare(ltrim($version, 'v'), ltrim($latest, 'v'), '>'))) {
                    $latest = $version;
                }
            }
        }
        if ($latest === null) {
            throw new ReleaseUnavailableException('No assessable stable release was found.');
        }
        return $latest;
    }
}

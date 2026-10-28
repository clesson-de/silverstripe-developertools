<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Constants\VersionAssessmentStatus as Status;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * Caches shared release metadata separately from immutable customer snapshots.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class ReleaseVersionService
{
    use Injectable;

    /** Successful metadata is refreshed after six hours. */
    public const SUCCESS_TTL = 21600;
    /** Retry failed lookups after one hour. */
    public const FAILURE_TTL = 3600;

    /** Refreshes external sources during checks, never during CMS expansion. */
    public function refresh(array $inventory): void
    {
        $deadline = microtime(true) + 10;
        foreach ($this->items($inventory) as $item) {
            if (microtime(true) >= $deadline) {
                break;
            }
            if ($item['Key'] !== '' && $this->comparable($item['Current'], $item['Key']) !== null) {
                $this->refreshKey($item['Key']);
            }
        }
    }

    /** Returns cache-only rows for the expandable plain HTML table. */
    public function assess(array $inventory): array
    {
        $rows = [];
        foreach ($this->items($inventory) as $item) {
            $metadata = $item['Key'] === '' ? null : $this->cache()->get(hash('sha256', $item['Key']));
            $status = $this->status($item, $metadata);
            $rows[] = array_merge($item, [
                'Latest' => $metadata['version'] ?? '', 'Status' => $status,
                'Label' => Status::label($status), 'Source' => $metadata['source'] ?? '',
                'CheckedAt' => isset($metadata['checked_at']) ? gmdate('Y-m-d H:i:s', $metadata['checked_at']) . ' UTC' : '',
                'Error' => $metadata['error'] ?? '',
            ]);
        }
        return $rows;
    }

    /** Counts comparable components once, including CMS also listed as a package. */
    public function actionRequired(array $inventory): ?bool
    {
        $components = [];
        foreach ($this->assess($inventory) as $row) {
            if (in_array($row['Status'], [Status::CURRENT, Status::UPDATE], true)) {
                $components[$row['Key']] = $row['Status'];
            }
        }
        if (!$components) {
            return null;
        }
        $outdated = count(array_filter($components, static fn(string $status): bool => $status === Status::UPDATE));
        return $outdated * 2 > count($components);
    }

    /** Builds product and package rows without calling any release source. */
    private function items(array $inventory): array
    {
        $packages = array_column($inventory['composer_packages'] ?? [], 'version', 'name');
        $product = $inventory['database_product'] ?? '';
        $rows = [
            ['Item' => 'PHP', 'Current' => $inventory['php_version'], 'Key' => 'php'],
            ['Item' => 'Silverstripe CMS', 'Current' => $packages['silverstripe/cms'] ?? '', 'Key' => 'silverstripe/cms'],
            ['Item' => _t(__CLASS__ . '.DATABASE', 'Database') . ($product ? ' (' . $product . ')' : ''), 'Current' => $inventory['database_version'],
                'Key' => in_array($product, ['mysql', 'mariadb'], true) ? $product : ''],
        ];
        foreach ($packages as $name => $version) {
            $rows[] = ['Item' => $name, 'Current' => $version, 'Key' => $name];
        }
        return $rows;
    }

    /** Refreshes one shared cached source, retaining previous versions on failure. */
    private function refreshKey(string $key): void
    {
        $cacheKey = hash('sha256', $key);
        $old = $this->cache()->get($cacheKey);
        if (is_array($old) && ($old['retry_at'] ?? 0) > time()) {
            return;
        }
        $metadata = ['checked_at' => time(), 'source' => StableReleaseProvider::singleton()->source($key)];
        try {
            $metadata += ['version' => StableReleaseProvider::singleton()->latest($key), 'error' => '', 'retry_at' => time() + self::SUCCESS_TTL, 'unavailable' => false];
        } catch (ReleaseUnavailableException $exception) {
            $metadata += ['version' => '', 'error' => $exception->getMessage(), 'retry_at' => time() + self::SUCCESS_TTL, 'unavailable' => true];
        } catch (RuntimeException $exception) {
            $metadata += ['version' => $old['version'] ?? '', 'error' => $exception->getMessage(), 'retry_at' => time() + self::FAILURE_TTL, 'unavailable' => false];
        }
        $this->cache()->set($cacheKey, $metadata, 30 * 86400);
    }

    /** Chooses a truthful status, never green for failed or expired metadata. */
    private function status(array $item, mixed $metadata): string
    {
        $current = $this->comparable($item['Current'], $item['Key']);
        if ($item['Key'] === '' || $current === null || !is_array($metadata)) {
            return Status::UNKNOWN;
        }
        if ($metadata['unavailable'] ?? false) {
            return Status::UNKNOWN;
        }
        if ($metadata['error'] !== '' || time() >= $metadata['retry_at']) {
            return Status::FAILED;
        }
        $latest = $this->comparable($metadata['version'], $item['Key']);
        if ($latest === null) {
            return Status::UNKNOWN;
        }
        return version_compare($current, $latest, '<') ? Status::UPDATE : Status::CURRENT;
    }

    /** Allows numeric product versions, but never treats Composer dev branches as releases. */
    private function comparable(string $version, string $key): ?string
    {
        if (in_array($key, ['mysql', 'mariadb'], true)) {
            return preg_match('/^(?:5\.5\.5-)?(\d+\.\d+\.\d+)(?:[-+].*)?$/D', $version, $match) === 1
                ? $match[1] : null;
        }
        return preg_match('/^v?(\d+\.\d+(?:\.\d+){0,2})$/D', $version, $match) === 1 ? $match[1] : null;
    }

    /** Returns the configured project-wide metadata cache. */
    private function cache(): CacheInterface
    {
        return Injector::inst()->get(CacheInterface::class . '.DeveloperToolsReleases');
    }
}

<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * Assesses installed runtime versions against latest stable and lifecycle metadata.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class VersionSupportCheck
{
    use Injectable;

    private const SUCCESS_TTL = 21600;
    private const FAILURE_TTL = 3600;

    /**
     * @return array{status: string, message: string, meta: array<string, string>}
     */
    public function assess(
        string $id,
        string $title,
        string $installed,
        string $latestKey,
        string $lifecycleKey,
        string $category
    ): array {
        $installedComparable = $this->comparable($installed, $latestKey);
        if ($installedComparable === null) {
            return $this->outcome(
                HealthStatus::UNKNOWN,
                _t(__CLASS__ . '.UNSUPPORTED_FORMAT', 'Installed version format is not assessable.'),
                $category,
                $installed,
                '',
                '',
                HealthReport::STATE_NOT_TESTABLE,
                'low',
                'low',
                'Installed version could not be normalized for a trusted comparison.'
            );
        }

        [$latestVersion, $latestError] = $this->latest($latestKey);
        $latestComparable = $latestVersion !== '' ? $this->comparable($latestVersion, $latestKey) : null;

        [$eolDate, $eolError] = $this->eolDate($lifecycleKey, $installedComparable);

        if ($eolDate !== null && $eolDate < new DateTimeImmutable('today UTC')) {
            return $this->outcome(
                HealthStatus::ERROR,
                _t(__CLASS__ . '.EOL', 'Installed version is end-of-life and no longer receives security fixes.'),
                $category,
                $installedComparable,
                $latestComparable ?? '',
                $eolDate->format('Y-m-d'),
                HealthReport::STATE_PROBLEM,
                'critical',
                'critical',
                'Lifecycle source indicates this cycle is past end-of-life date.'
            );
        }

        if ($latestComparable !== null && version_compare($installedComparable, $latestComparable, '<')) {
            $severity = ($eolDate !== null && $eolDate <= new DateTimeImmutable('+90 days UTC')) ? 'high' : 'medium';
            $priority = $severity === 'high' ? 'high' : 'medium';
            $message = $eolDate !== null && $eolDate <= new DateTimeImmutable('+90 days UTC')
                ? _t(__CLASS__ . '.UPDATE_SOON_EOL', 'Update available and support end is near.')
                : _t(__CLASS__ . '.UPDATE', 'Update available compared to the latest stable release.');
            return $this->outcome(
                HealthStatus::WARNING,
                $message,
                $category,
                $installedComparable,
                $latestComparable,
                $eolDate?->format('Y-m-d') ?? '',
                HealthReport::STATE_RECOMMENDATION,
                $severity,
                $priority,
                'Installed version is behind latest stable release metadata.'
            );
        }

        if ($latestComparable !== null) {
            return $this->outcome(
                HealthStatus::OK,
                _t(__CLASS__ . '.CURRENT', 'Installed version is current and no EOL risk is currently known.'),
                $category,
                $installedComparable,
                $latestComparable,
                $eolDate?->format('Y-m-d') ?? '',
                HealthReport::STATE_PASSED,
                'none',
                'low',
                'Installed version is not behind latest stable metadata.'
            );
        }

        $errorSummary = trim($latestError . ' ' . $eolError);
        return $this->outcome(
            HealthStatus::UNKNOWN,
            _t(__CLASS__ . '.UNKNOWN', 'Official release or lifecycle data is currently insufficient for a trustworthy comparison.'),
            $category,
            $installedComparable,
            '',
            '',
            HealthReport::STATE_NOT_TESTABLE,
            'low',
            'low',
            $errorSummary !== '' ? $errorSummary : 'No assessable official data source could be confirmed.'
        );
    }

    /** @return array{0: string, 1: string} */
    private function latest(string $key): array
    {
        $cacheKey = hash('sha256', 'version-support.latest.' . $key);
        $cached = $this->cache()->get($cacheKey);
        if (is_array($cached) && isset($cached['retry_at']) && is_int($cached['retry_at']) && $cached['retry_at'] > time()) {
            return [is_string($cached['version'] ?? null) ? $cached['version'] : '', is_string($cached['error'] ?? null) ? $cached['error'] : ''];
        }

        $data = ['version' => '', 'error' => '', 'retry_at' => time() + self::FAILURE_TTL];
        try {
            $data['version'] = StableReleaseProvider::singleton()->latest($key);
            $data['retry_at'] = time() + self::SUCCESS_TTL;
        } catch (RuntimeException $exception) {
            $data['error'] = $exception->getMessage();
        }
        $this->cache()->set($cacheKey, $data, 30 * 86400);
        return [$data['version'], $data['error']];
    }

    /** @return array{0: ?DateTimeImmutable, 1: string} */
    private function eolDate(string $lifecycleKey, string $installedComparable): array
    {
        $cacheKey = hash('sha256', 'version-support.eol.' . $lifecycleKey);
        $cached = $this->cache()->get($cacheKey);
        if (!is_array($cached) || !isset($cached['entries']) || !is_array($cached['entries'])
            || !isset($cached['retry_at']) || !is_int($cached['retry_at']) || $cached['retry_at'] <= time()) {
            $cached = ['entries' => [], 'error' => '', 'retry_at' => time() + self::FAILURE_TTL];
            try {
                $entries = ReleaseMetadataClient::singleton()->fetch('https://endoflife.date/api/' . rawurlencode($lifecycleKey) . '.json');
                $cached['entries'] = $entries;
                $cached['retry_at'] = time() + self::SUCCESS_TTL;
            } catch (RuntimeException $exception) {
                $cached['error'] = $exception->getMessage();
            }
            $this->cache()->set($cacheKey, $cached, 30 * 86400);
        }

        $cycle = $this->cycleOf($installedComparable, $lifecycleKey);
        foreach ($cached['entries'] as $entry) {
            if (!is_array($entry) || !is_string($entry['cycle'] ?? null)) {
                continue;
            }
            if ($entry['cycle'] !== $cycle) {
                continue;
            }
            $raw = $entry['eol'] ?? null;
            if (!is_string($raw) || $raw === '' || strtolower($raw) === 'false') {
                return [null, ''];
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            if ($date instanceof DateTimeImmutable) {
                return [$date, ''];
            }
        }
        $error = is_string($cached['error'] ?? null) ? $cached['error'] : '';
        return [null, $error !== '' ? $error : 'No matching lifecycle cycle found.'];
    }

    private function cycleOf(string $version, string $key): string
    {
        $parts = explode('.', $version);
        if ($key === 'silverstripe') {
            return $parts[0] ?? $version;
        }
        return isset($parts[1]) ? $parts[0] . '.' . $parts[1] : $version;
    }

    private function comparable(string $version, string $key): ?string
    {
        if (in_array($key, ['mysql', 'mariadb'], true)) {
            return preg_match('/^(?:5\.5\.5-)?(\d+\.\d+\.\d+)(?:[-+].*)?$/D', $version, $match) === 1
                ? $match[1] : null;
        }
        return preg_match('/^v?(\d+\.\d+(?:\.\d+){0,2})$/D', $version, $match) === 1 ? $match[1] : null;
    }

    /** @return array{status: string, message: string, meta: array<string, string>} */
    private function outcome(
        string $status,
        string $message,
        string $category,
        string $installed,
        string $latest,
        string $eol,
        string $state,
        string $severity,
        string $priority,
        string $evidence
    ): array {
        return [
            'status' => $status,
            'message' => $message,
            'meta' => [
                'category' => $category,
                'source' => HealthReport::SOURCE_EXTERNAL,
                'state' => $state,
                'severity' => $severity,
                'priority' => $priority,
                'target' => 'Installed runtime version',
                'evidence' => trim($evidence . ' Installed=' . $installed . '; Latest=' . $latest . '; EOL=' . $eol),
                'impact' => 'Outdated or unsupported platform versions increase exploit and maintenance risk.',
                'remediation' => 'Plan and execute supported-version upgrades with compatibility testing.',
                'limitations' => 'Lifecycle metadata may differ by distribution/vendor support policies.',
                'reference' => 'https://endoflife.date/',
            ],
        ];
    }

    private function cache(): CacheInterface
    {
        return Injector::inst()->get(CacheInterface::class . '.DeveloperToolsReleases');
    }
}


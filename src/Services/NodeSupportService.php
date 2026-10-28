<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus as S;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * Evaluates numeric Node build requirements against the official release schedule.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class NodeSupportService
{
    use Injectable;

    /** Official public support dates; no customer information is sent. */
    public const SOURCE = 'https://raw.githubusercontent.com/nodejs/Release/main/schedule.json';

    /** Returns unknown for aliases, source failures, malformed schedules or future releases. */
    public function assess(string $version): array
    {
        if (!preg_match('/\Av?([1-9][0-9]*)(?:\.[0-9]+){0,2}\z/D', $version, $matches)) {
            return [S::UNKNOWN, _t(__CLASS__ . '.ALIAS', 'Only numeric Node requirements can be assessed; aliases are not resolved.')];
        }
        $metadata = $this->schedule();
        $series = $metadata['data']['v' . $matches[1]] ?? null;
        if (($metadata['error'] ?? true) || !is_array($series)
            || !$this->date($series['start'] ?? null) || !$this->date($series['end'] ?? null)
            || $series['start'] >= $series['end']
            || $series['start'] > gmdate('Y-m-d')
        ) {
            return [S::UNKNOWN, _t(__CLASS__ . '.UNAVAILABLE', 'No current, valid official Node support data is available.')];
        }
        return $this->outcome($series['end']);
    }

    /** Successful schedules live six hours; failures are explicit and retried after one hour. */
    private function schedule(): array
    {
        $cache = Injector::inst()->get(CacheInterface::class . '.DeveloperToolsReleases');
        $metadata = $cache->get('node-support-schedule');
        if (is_array($metadata) && ($metadata['retry_at'] ?? 0) > time()) {
            return $metadata;
        }
        try {
            $data = ReleaseMetadataClient::singleton()->fetch(self::SOURCE);
            $metadata = ['data' => $data, 'error' => false, 'retry_at' => time() + 21600];
        } catch (RuntimeException $exception) {
            $metadata = ['data' => [], 'error' => true, 'retry_at' => time() + 3600];
        }
        $cache->set('node-support-schedule', $metadata, 21600);
        return $metadata;
    }

    /** Uses calendar dates rather than hard-coded supported major versions. */
    private function outcome(string $end): array
    {
        $expired = $end <= gmdate('Y-m-d');
        return [$expired ? S::WARNING : S::OK, _t(__CLASS__ . '.SUPPORT_END',
            'Declared build requirement; official Node support ends on {date}. This is not the installed build runtime.',
            ['date' => $end])];
    }

    /** Validates upstream dates instead of accepting lexical date-like values. */
    private function date(mixed $date): bool
    {
        if (!is_string($date) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $date) !== 1) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}

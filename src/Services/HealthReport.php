<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus;
use DomainException;
use DateTimeImmutable;
use SilverStripe\Core\Injector\Injectable;
use stdClass;

/**
 * Validates bounded health results at the producer and consumer boundary.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class HealthReport
{
    use Injectable;

    /** Maximum results in one report. */
    public const MAX_CHECKS = 50;
    /** Required base contract kept stable for backward compatibility. */
    private const REQUIRED_FIELDS = ['id', 'title', 'status', 'message', 'checked_at'];
    /** Optional metadata fields for richer, explicit evidence reporting. */
    private const OPTIONAL_FIELDS = [
        'category', 'source', 'state', 'severity', 'priority',
        'evidence', 'target', 'impact', 'remediation', 'limitations', 'reference',
    ];

    public const SOURCE_LOCAL = 'local';
    public const SOURCE_EXTERNAL = 'external';
    public const SOURCE_MANUAL = 'manual';

    public const STATE_PASSED = 'passed';
    public const STATE_PROBLEM = 'problem';
    public const STATE_RECOMMENDATION = 'recommendation';
    public const STATE_NOT_APPLICABLE = 'not_applicable';
    public const STATE_NOT_TESTABLE = 'not_testable';
    public const STATE_FAILED = 'failed';

    private const STATES = [
        self::STATE_PASSED,
        self::STATE_PROBLEM,
        self::STATE_RECOMMENDATION,
        self::STATE_NOT_APPLICABLE,
        self::STATE_NOT_TESTABLE,
        self::STATE_FAILED,
    ];
    private const SOURCES = [self::SOURCE_LOCAL, self::SOURCE_EXTERNAL, self::SOURCE_MANUAL];
    private const SEVERITIES = ['none', 'low', 'medium', 'high', 'critical'];
    private const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    /** Rejects malformed results and duplicate IDs; order does not matter. */
    public function normalize(mixed $results, bool $withCentralCheck = false): array
    {
        $limit = self::MAX_CHECKS + ($withCentralCheck ? 1 : 0);
        if (!is_array($results) || !array_is_list($results) || count($results) > $limit) {
            throw new DomainException('Invalid health report.');
        }
        $sorted = [];
        foreach ($results as $result) {
            $row = $this->row($result);
            if (isset($sorted[$row['id']])) {
                throw new DomainException('Duplicate healthcheck ID.');
            }
            $sorted[$row['id']] = $row;
        }
        ksort($sorted);
        return array_values($sorted);
    }

    /** Creates a timestamped result using the same validation as remote reports. */
    public function result(string $id, string $title, string $status, string $message, array $meta = []): array
    {
        $defaults = $this->metaDefaults($status);
        return $this->row([
            'id' => $id, 'title' => $title, 'status' => $status,
            'message' => $message, 'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ...$defaults,
            ...array_intersect_key($meta, array_flip(self::OPTIONAL_FIELDS)),
        ]);
    }

    /** Validates exact keys, lengths and a UTC second-resolution timestamp. */
    private function row(mixed $result): array
    {
        $row = $result instanceof stdClass ? get_object_vars($result) : $result;
        if (!is_array($row)) {
            throw new DomainException('Invalid healthcheck fields.');
        }
        $allowed = array_merge(self::REQUIRED_FIELDS, self::OPTIONAL_FIELDS);
        if (array_diff(array_keys($row), $allowed) || array_diff(self::REQUIRED_FIELDS, array_keys($row))) {
            throw new DomainException('Invalid healthcheck fields.');
        }
        $row += $this->metaDefaults((string) ($row['status'] ?? ''));
        foreach (self::REQUIRED_FIELDS as $key) {
            $this->text($row[$key] ?? null, $key === 'message' ? 1000 : 200);
        }
        $this->text($row['category'], 80);
        $this->text($row['source'], 20);
        $this->text($row['state'], 40);
        $this->text($row['severity'], 20);
        $this->text($row['priority'], 20);
        foreach (['evidence', 'target', 'impact', 'remediation', 'limitations', 'reference'] as $key) {
            $this->text($row[$key], 500);
        }
        $this->validateOutcome($row);
        return array_intersect_key($row, array_flip($allowed));
    }

    /** Ensures stable IDs, known outcomes and a real observation time. */
    private function validateOutcome(array $row): void
    {
        if (!preg_match('/\A[a-z0-9][a-z0-9_.-]{0,99}\z/D', $row['id'])
            || !isset(HealthStatus::options()[$row['status']]) || $row['title'] === ''
            || !$this->validTimestamp($row['checked_at'])
            || !in_array($row['source'], self::SOURCES, true)
            || !in_array($row['state'], self::STATES, true)
            || !in_array($row['severity'], self::SEVERITIES, true)
            || !in_array($row['priority'], self::PRIORITIES, true)
            || !preg_match('/\A[a-z0-9][a-z0-9_.-]{0,79}\z/D', $row['category'])
        ) {
            throw new DomainException('Invalid healthcheck outcome.');
        }
    }

    /** @return array{category: string, source: string, state: string, severity: string, priority: string, evidence: string, target: string, impact: string, remediation: string, limitations: string, reference: string} */
    private function metaDefaults(string $status): array
    {
        $state = match ($status) {
            HealthStatus::OK => self::STATE_PASSED,
            HealthStatus::WARNING => self::STATE_RECOMMENDATION,
            HealthStatus::ERROR => self::STATE_PROBLEM,
            default => self::STATE_NOT_TESTABLE,
        };
        $severity = match ($status) {
            HealthStatus::OK => 'none',
            HealthStatus::WARNING => 'medium',
            HealthStatus::ERROR => 'high',
            default => 'low',
        };
        $priority = match ($status) {
            HealthStatus::OK => 'low',
            HealthStatus::WARNING => 'medium',
            HealthStatus::ERROR => 'high',
            default => 'low',
        };
        return [
            'category' => 'system.general',
            'source' => self::SOURCE_LOCAL,
            'state' => $state,
            'severity' => $severity,
            'priority' => $priority,
            'evidence' => '',
            'target' => '',
            'impact' => '',
            'remediation' => '',
            'limitations' => '',
            'reference' => '',
        ];
    }

    /** Allows plain UTF-8 prose without markup or control characters. */
    private function text(mixed $value, int $limit): void
    {
        if (!is_string($value) || strlen($value) > $limit || preg_match('//u', $value) !== 1
            || preg_match('/[<>\x00-\x1f\x7f]/', $value)
        ) {
            throw new DomainException('Invalid healthcheck text.');
        }
    }

    /** Rejects impossible dates as well as non-UTC or relative timestamp strings. */
    private function validTimestamp(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value);
        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value;
    }
}

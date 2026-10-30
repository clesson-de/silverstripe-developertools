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
    /** Exact result contract. */
    private const FIELDS = ['id', 'title', 'status', 'message', 'checked_at'];

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
    public function result(string $id, string $title, string $status, string $message): array
    {
        return $this->row([
            'id' => $id, 'title' => $title, 'status' => $status,
            'message' => $message, 'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /** Validates exact keys, lengths and a UTC second-resolution timestamp. */
    private function row(mixed $result): array
    {
        $row = $result instanceof stdClass ? get_object_vars($result) : $result;
        if (!is_array($row) || count($row) !== count(self::FIELDS) || array_diff(array_keys($row), self::FIELDS)) {
            throw new DomainException('Invalid healthcheck fields.');
        }
        foreach (self::FIELDS as $key) {
            $this->text($row[$key] ?? null, $key === 'message' ? 1000 : 200);
        }
        $this->validateOutcome($row);
        return array_intersect_key($row, array_flip(self::FIELDS));
    }

    /** Ensures stable IDs, known outcomes and a real observation time. */
    private function validateOutcome(array $row): void
    {
        if (!preg_match('/\A[a-z0-9][a-z0-9_.-]{0,99}\z/D', $row['id'])
            || !isset(HealthStatus::options()[$row['status']]) || $row['title'] === ''
            || !$this->validTimestamp($row['checked_at'])
        ) {
            throw new DomainException('Invalid healthcheck outcome.');
        }
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

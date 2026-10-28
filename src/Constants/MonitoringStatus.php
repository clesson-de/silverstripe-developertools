<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Constants;

/**
 * Outcomes of completed monitoring attempts.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Constants
 */
final class MonitoringStatus
{
    /** A new inventory was observed. */
    public const CHANGED = 'changed';
    /** The latest inventory is still current. */
    public const UNCHANGED = 'unchanged';
    /** No valid inventory could be obtained. */
    public const FAILED = 'failed';

    /** Returns translated outcomes. */
    public static function options(): array
    {
        return [
            self::CHANGED => _t(__CLASS__ . '.CHANGED', 'Changed'),
            self::UNCHANGED => _t(__CLASS__ . '.UNCHANGED', 'Unchanged'),
            self::FAILED => _t(__CLASS__ . '.FAILED', 'Failed'),
        ];
    }

    /** Resolves a stored outcome without inventing a successful default. */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? (string) $value;
    }
}

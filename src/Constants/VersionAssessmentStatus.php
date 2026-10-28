<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Constants;

/**
 * Version assessment states are independent of website fetch results.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Constants
 */
class VersionAssessmentStatus
{
    /** Installed version is not behind the latest stable release. */
    public const CURRENT = 'current';
    /** A newer stable release exists, without a compatibility promise. */
    public const UPDATE = 'update';
    /** Metadata could not be refreshed. */
    public const FAILED = 'failed';
    /** No trustworthy comparison is possible. */
    public const UNKNOWN = 'unknown';

    /** Returns translated status labels. */
    public static function options(): array
    {
        return [
            self::CURRENT => _t(__CLASS__ . '.CURRENT', 'Current'),
            self::UPDATE => _t(__CLASS__ . '.UPDATE', 'Update available'),
            self::FAILED => _t(__CLASS__ . '.FAILED', 'Release lookup failed or stale'),
            self::UNKNOWN => _t(__CLASS__ . '.UNKNOWN', 'Not assessable'),
        ];
    }

    /** Returns a translated label. */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? (string) $value;
    }
}

<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Constants;

/**
 * Health outcomes are independent of installed-version comparisons.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Constants
 */
class HealthStatus
{
    /** Check passed. */
    public const OK = 'ok';
    /** Attention is recommended. */
    public const WARNING = 'warning';
    /** A critical requirement failed. */
    public const ERROR = 'error';
    /** No trustworthy assessment is available. */
    public const UNKNOWN = 'unknown';

    /** Returns translated outcome labels. */
    public static function options(): array
    {
        return [
            self::OK => _t(__CLASS__ . '.OK', 'OK'),
            self::WARNING => _t(__CLASS__ . '.WARNING', 'Warning'),
            self::ERROR => _t(__CLASS__ . '.ERROR', 'Error'),
            self::UNKNOWN => _t(__CLASS__ . '.UNKNOWN', 'Not assessable'),
        ];
    }

    /** Returns a translated label. */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? (string) $value;
    }
}

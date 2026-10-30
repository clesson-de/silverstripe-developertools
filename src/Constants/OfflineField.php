<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Constants;

/**
 * Persistent singleton settings for the website maintenance gate.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Constants
 */
final class OfflineField
{
    /** Enables the maintenance gate. */
    public const ACTIVE = 'OfflineActive';
    /** Inclusive optional start time. */
    public const START = 'OfflineStart';
    /** Exclusive optional end time. */
    public const END = 'OfflineEnd';
    /** Addresses allowed through the maintenance gate. */
    public const IP_EXCEPTIONS = 'OfflineIPExceptions';

    /** Provides translated CMS labels for all settings. */
    public static function options(): array
    {
        return [
            self::ACTIVE => _t(__CLASS__ . '.ACTIVE', 'Enable offline mode'),
            self::START => _t(__CLASS__ . '.START', 'Start date and time'),
            self::END => _t(__CLASS__ . '.END', 'End date and time'),
            self::IP_EXCEPTIONS => _t(__CLASS__ . '.IP_EXCEPTIONS', 'IP exceptions'),
        ];
    }

    /** Resolves a field identifier to its CMS label. */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? (string) $value;
    }
}

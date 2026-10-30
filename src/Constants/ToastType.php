<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Constants;

/**
 * Supported toast severities.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Constants
 */
final class ToastType
{
    /** Informational status. */
    public const INFO = 'info';
    /** Warning status. */
    public const WARNING = 'warning';
    /** Urgent alert. */
    public const ALERT = 'alert';

    /** Returns translated choices. */
    public static function options(): array
    {
        return [
            self::INFO => _t(__CLASS__ . '.INFO', 'Info'),
            self::WARNING => _t(__CLASS__ . '.WARNING', 'Warning'),
            self::ALERT => _t(__CLASS__ . '.ALERT', 'Alert'),
        ];
    }

    /** Resolves a translated choice. */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? (string) $value;
    }
}

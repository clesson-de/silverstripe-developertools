<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Constants;

/**
 * Environment selection; test is included only by the all choice.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Constants
 */
final class ToastEnvironment
{
    /** Any runtime environment. */
    public const ALL = 'all';
    /** Development only. */
    public const DEV = 'dev';
    /** Production only. */
    public const LIVE = 'live';

    /** Returns translated choices. */
    public static function options(): array
    {
        return [
            self::ALL => _t(__CLASS__ . '.ALL', 'All environments'),
            self::DEV => _t(__CLASS__ . '.DEV', 'Development only'),
            self::LIVE => _t(__CLASS__ . '.LIVE', 'Live only'),
        ];
    }

    /** Resolves a translated choice. */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? (string) $value;
    }
}

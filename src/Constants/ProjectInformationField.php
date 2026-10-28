<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Constants;

/**
 * Stable JSON keys and CMS labels for project information.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Constants
 */
class ProjectInformationField
{
    /** PHP runtime version. */
    public const PHP_VERSION = 'php_version';

    /** Installed Silverstripe framework version. */
    public const SILVERSTRIPE_VERSION = 'silverstripe_version';

    /** Connected database server version. */
    public const DATABASE_VERSION = 'database_version';

    /**
     * Returns the translated labels for all information fields.
     */
    public static function options(): array
    {
        return [
            self::PHP_VERSION => _t(__CLASS__ . '.PHP_VERSION', 'PHP version'),
            self::SILVERSTRIPE_VERSION => _t(__CLASS__ . '.SILVERSTRIPE_VERSION', 'Silverstripe framework version'),
            self::DATABASE_VERSION => _t(__CLASS__ . '.DATABASE_VERSION', 'Database server version'),
        ];
    }

    /**
     * Returns a translated label for a field key.
     */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? (string) $value;
    }
}

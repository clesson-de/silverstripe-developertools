<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Constants;

/**
 * Vertical toast anchors.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Constants
 */
final class ToastVerticalPosition
{
    /** Top edge. */
    public const TOP = 'top';
    /** Vertical centre. */
    public const CENTER = 'center';
    /** Bottom edge. */
    public const BOTTOM = 'bottom';

    /** Returns translated choices. */
    public static function options(): array
    {
        return [
            self::TOP => _t(__CLASS__ . '.TOP', 'Top'),
            self::CENTER => _t(__CLASS__ . '.CENTER', 'Centre'),
            self::BOTTOM => _t(__CLASS__ . '.BOTTOM', 'Bottom'),
        ];
    }

    /** Resolves a translated choice. */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? (string) $value;
    }
}

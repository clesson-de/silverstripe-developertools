<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Constants;

/**
 * Horizontal toast anchors.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Constants
 */
final class ToastHorizontalPosition
{
    /** Left edge. */
    public const LEFT = 'left';
    /** Horizontal centre. */
    public const CENTER = 'center';
    /** Right edge. */
    public const RIGHT = 'right';

    /** Returns translated choices. */
    public static function options(): array
    {
        return [
            self::LEFT => _t(__CLASS__ . '.LEFT', 'Left'),
            self::CENTER => _t(__CLASS__ . '.CENTER', 'Centre'),
            self::RIGHT => _t(__CLASS__ . '.RIGHT', 'Right'),
        ];
    }

    /** Resolves a translated choice. */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? (string) $value;
    }
}

<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Constants;

/**
 * Toast field and relation identifiers.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Constants
 */
final class ToastField
{
    /** Optional plaintext heading. */
    public const TITLE = 'Title';
    /** Message severity. */
    public const TYPE = 'Type';
    /** Target runtime environment. */
    public const ENVIRONMENT = 'Environment';
    /** Optional visitor address rules. */
    public const IP_RANGES = 'IPRanges';
    /** Composite Markdown content. */
    public const MESSAGE = 'Message';
    /** Plaintext column maintained by the composite Markdown field. */
    public const MESSAGE_PLAIN = 'MessagePlain';
    /** Automatic dismissal delay in seconds. */
    public const AUTO_HIDE_SECONDS = 'AutoHideSeconds';
    /** Enables the manual close control. */
    public const REMOVABLE = 'Removable';
    /** Horizontal anchor. */
    public const HORIZONTAL_POSITION = 'HorizontalPosition';
    /** Vertical anchor. */
    public const VERTICAL_POSITION = 'VerticalPosition';
    /** Combined position overview. */
    public const POSITION = 'Position';
    /** Publication switch. */
    public const ACTIVE = 'Active';
    /** Optional beginning of the display period. */
    public const START_DATE = 'StartDate';
    /** Optional end of the display period. */
    public const END_DATE = 'EndDate';
    /** Optional audience groups. */
    public const GROUPS = 'Groups';
    /** Combined audience and schedule indicators. */
    public const FILTERS = 'Filters';
    /** Owning settings record. */
    public const SETTINGS = 'Settings';
    /** Settings relation. */
    public const TOAST_MESSAGES = 'ToastMessages';
    /** Plaintext list preview. */
    public const PREVIEW = 'Preview';

    /** Returns translated field labels. */
    public static function options(): array
    {
        return [
            self::TITLE => _t(__CLASS__ . '.TITLE', 'Title'),
            self::TYPE => _t(__CLASS__ . '.TYPE', 'Type'),
            self::ENVIRONMENT => _t(__CLASS__ . '.ENVIRONMENT', 'Environment'),
            self::IP_RANGES => _t(__CLASS__ . '.IP_RANGES', 'IP addresses and ranges'),
            self::MESSAGE => _t(__CLASS__ . '.MESSAGE', 'Message'),
            self::AUTO_HIDE_SECONDS => _t(__CLASS__ . '.AUTO_HIDE_SECONDS', 'Autohide after (seconds)'),
            self::REMOVABLE => _t(__CLASS__ . '.REMOVABLE', 'Visitors can dismiss the message using a close button'),
            self::HORIZONTAL_POSITION => _t(__CLASS__ . '.HORIZONTAL_POSITION', 'Horizontal position'),
            self::VERTICAL_POSITION => _t(__CLASS__ . '.VERTICAL_POSITION', 'Vertical position'),
            self::POSITION => _t(__CLASS__ . '.POSITION', 'Position'),
            self::ACTIVE => _t(__CLASS__ . '.ACTIVE', 'Active'),
            self::START_DATE => _t(__CLASS__ . '.START_DATE', 'Start date and time'),
            self::END_DATE => _t(__CLASS__ . '.END_DATE', 'End date and time'),
            self::GROUPS => _t(__CLASS__ . '.GROUPS', 'User groups'),
            self::FILTERS => _t(__CLASS__ . '.FILTERS', 'Filters'),
            self::TOAST_MESSAGES => _t(__CLASS__ . '.TOAST_MESSAGES', 'Overlays'),
            self::PREVIEW => _t(__CLASS__ . '.PREVIEW', 'Message'),
        ] + self::relationLabels();
    }

    /** Supplies labels for the owning relation and searchable composite column. */
    private static function relationLabels(): array
    {
        return [
            self::SETTINGS => _t(__CLASS__ . '.SETTINGS', 'Configuration'),
            self::MESSAGE_PLAIN => _t(__CLASS__ . '.MESSAGE', 'Message'),
        ];
    }

    /** Resolves a field label. */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? (string) $value;
    }
}

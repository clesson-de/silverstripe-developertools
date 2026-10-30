<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Constants;

/**
 * Shared monitoring field and relation identifiers.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Constants
 */
final class MonitoringField
{
    /** Configuration fields. */
    public const NAME = 'Name';
    /** Canonical endpoint base. */
    public const BASE_URL = 'BaseURL';
    /** Scheduled polling switch. */
    public const ACTIVE = 'Active';
    /** Encrypted credential, never scaffolded. */
    public const API_TOKEN = 'ApiToken';
    /** Write-only form input. */
    public const REPLACEMENT_TOKEN = 'ReplacementToken';
    /** First attempted check. */
    public const FIRST_CHECKED_AT = 'FirstCheckedAt';
    /** Most recent attempted check. */
    public const LAST_CHECKED_AT = 'LastCheckedAt';
    /** Most recent valid response. */
    public const LAST_SUCCESSFUL_CHECK_AT = 'LastSuccessfulCheckAt';
    /** Most recent outcome. */
    public const LAST_STATUS = 'LastStatus';
    /** Sanitized failure. */
    public const LAST_ERROR = 'LastError';
    /** Owning configuration. */
    public const CONFIG = 'Config';
    /** Current valid snapshot. */
    public const LATEST_SNAPSHOT = 'LatestSnapshot';
    /** Snapshot history. */
    public const SNAPSHOTS = 'Snapshots';
    /** Attempt history. */
    public const CHECKS = 'Checks';
    /** Owning website. */
    public const WEBSITE = 'Website';
    /** Snapshot associated with an attempt. */
    public const SNAPSHOT = 'Snapshot';
    /** Detection timestamp. */
    public const OBSERVED_AT = 'ObservedAt';
    /** Canonical inventory JSON. */
    public const PAYLOAD = 'Payload';
    /** Computed human-readable inventory. */
    public const INVENTORY = 'Inventory';
    /** SHA-256 of the canonical inventory. */
    public const CONTENT_HASH = 'ContentHash';
    /** Attempt start timestamp. */
    public const STARTED_AT = 'StartedAt';
    /** Attempt finish timestamp. */
    public const FINISHED_AT = 'FinishedAt';
    /** Attempt outcome. */
    public const STATUS = 'Status';
    /** Whitelisted error description. */
    public const ERROR_MESSAGE = 'ErrorMessage';
    /** Computed detection timestamp. */
    public const LAST_CHANGE_AT = 'LastChangeAt';
    /** Raw per-attempt health report, separate from the inventory snapshot. */
    public const HEALTH_RESULTS = 'HealthResults';
    /** Aggregate per-attempt health outcome. */
    public const HEALTH_STATUS = 'HealthStatus';
    /** Independently collected central HTTP redirect outcome. */
    public const HTTP_REDIRECT_RESULT = 'HttpRedirectResult';

    /** Returns translated labels for model fields and relations. */
    public static function options(): array
    {
        return self::configurationLabels() + self::historyLabels() + [
            self::HEALTH_RESULTS => _t(__CLASS__ . '.HEALTH_RESULTS', 'Health results JSON'),
            self::HEALTH_STATUS => _t(__CLASS__ . '.HEALTH_STATUS', 'System health'),
            self::HTTP_REDIRECT_RESULT => _t(__CLASS__ . '.HTTP_REDIRECT_RESULT', 'HTTP redirect result JSON'),
        ];
    }

    /** Returns configuration labels. */
    private static function configurationLabels(): array
    {
        return [
            self::NAME => _t(__CLASS__ . '.NAME', 'Name'),
            self::BASE_URL => _t(__CLASS__ . '.BASE_URL', 'Base URL'),
            self::ACTIVE => _t(__CLASS__ . '.ACTIVE', 'Enabled'),
            self::API_TOKEN => _t(__CLASS__ . '.API_TOKEN', 'API token'),
            self::REPLACEMENT_TOKEN => _t(__CLASS__ . '.REPLACEMENT_TOKEN', 'New API token (leave blank to retain)'),
            self::FIRST_CHECKED_AT => _t(__CLASS__ . '.FIRST_CHECKED_AT', 'First check'),
            self::LAST_CHECKED_AT => _t(__CLASS__ . '.LAST_CHECKED_AT', 'Last check'),
            self::LAST_SUCCESSFUL_CHECK_AT => _t(__CLASS__ . '.LAST_SUCCESSFUL_CHECK_AT', 'Last successful check'),
            self::LAST_STATUS => _t(__CLASS__ . '.LAST_STATUS', 'Last status'),
            self::LAST_ERROR => _t(__CLASS__ . '.LAST_ERROR', 'Last error'),
            self::CONFIG => _t(__CLASS__ . '.CONFIG', 'Configuration'),
            self::LATEST_SNAPSHOT => _t(__CLASS__ . '.LATEST_SNAPSHOT', 'Latest snapshot'),
        ];
    }

    /** Returns history labels. */
    private static function historyLabels(): array
    {
        return [
            self::SNAPSHOTS => _t(__CLASS__ . '.SNAPSHOTS', 'Snapshots'),
            self::CHECKS => _t(__CLASS__ . '.CHECKS', 'Checks'),
            self::WEBSITE => _t(__CLASS__ . '.WEBSITE', 'Website'),
            self::SNAPSHOT => _t(__CLASS__ . '.SNAPSHOT', 'Snapshot'),
            self::OBSERVED_AT => _t(__CLASS__ . '.OBSERVED_AT', 'Change detected at'),
            self::PAYLOAD => _t(__CLASS__ . '.PAYLOAD', 'Inventory JSON'),
            self::INVENTORY => _t(__CLASS__ . '.INVENTORY', 'Inventory'),
            self::CONTENT_HASH => _t(__CLASS__ . '.CONTENT_HASH', 'Content hash'),
            self::STARTED_AT => _t(__CLASS__ . '.STARTED_AT', 'Started at'),
            self::FINISHED_AT => _t(__CLASS__ . '.FINISHED_AT', 'Finished at'),
            self::STATUS => _t(__CLASS__ . '.STATUS', 'Status'),
            self::ERROR_MESSAGE => _t(__CLASS__ . '.ERROR_MESSAGE', 'Error'),
            self::LAST_CHANGE_AT => _t(__CLASS__ . '.LAST_CHANGE_AT', 'Last detected change'),
        ];
    }

    /** Resolves a field label. */
    public static function label(mixed $value): string
    {
        return self::options()[$value] ?? (string) $value;
    }
}

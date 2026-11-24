<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\HealthChecks;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus;
use Clesson\Silverstripe\DeveloperTools\Constants\ProjectInformationField;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReport;
use Clesson\Silverstripe\DeveloperTools\Services\ProjectInformationService;
use Clesson\Silverstripe\DeveloperTools\Services\VersionSupportCheck;

/**
 * Evaluates PHP, Silverstripe framework and database version support state.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage HealthChecks
 */
class VersionSupportHealthCheck implements HealthCheck
{
    public function run(float $deadline): array
    {
        $report = HealthReport::singleton();
        $info = ProjectInformationService::singleton()->getInformation();

        $rows = [];

        $php = VersionSupportCheck::singleton()->assess(
            'version.php',
            'PHP version',
            (string) ($info[ProjectInformationField::PHP_VERSION] ?? ''),
            'php',
            'php',
            'runtime.php'
        );
        $rows[] = $report->result(
            'version.php',
            _t(__CLASS__ . '.PHP_TITLE', 'PHP version support'),
            $php['status'],
            $php['message'],
            $php['meta']
        );

        $framework = VersionSupportCheck::singleton()->assess(
            'version.silverstripe_framework',
            'Silverstripe framework version',
            (string) ($info[ProjectInformationField::SILVERSTRIPE_VERSION] ?? ''),
            'silverstripe/framework',
            'silverstripe',
            'runtime.silverstripe'
        );
        $rows[] = $report->result(
            'version.silverstripe_framework',
            _t(__CLASS__ . '.SS_TITLE', 'Silverstripe framework support'),
            $framework['status'],
            $framework['message'],
            $framework['meta']
        );

        $databaseProduct = ProjectInformationService::singleton()->getDatabaseProduct();
        $databaseVersion = (string) ($info[ProjectInformationField::DATABASE_VERSION] ?? '');
        if (in_array($databaseProduct, ['mysql', 'mariadb'], true)) {
            $database = VersionSupportCheck::singleton()->assess(
                'version.database',
                'Database server version',
                $databaseVersion,
                $databaseProduct,
                $databaseProduct,
                'runtime.database'
            );
            $rows[] = $report->result(
                'version.database',
                _t(__CLASS__ . '.DB_TITLE', 'Database server support') . ' (' . strtoupper($databaseProduct) . ')',
                $database['status'],
                $database['message'],
                $database['meta']
            );
        } else {
            $rows[] = $report->result(
                'version.database',
                _t(__CLASS__ . '.DB_TITLE', 'Database server support'),
                HealthStatus::UNKNOWN,
                _t(__CLASS__ . '.DB_UNKNOWN', 'Database product is not assessable for official lifecycle checks.'),
                [
                    'category' => 'runtime.database',
                    'source' => HealthReport::SOURCE_LOCAL,
                    'state' => HealthReport::STATE_NOT_TESTABLE,
                    'severity' => 'low',
                    'priority' => 'low',
                    'target' => 'Database server version',
                    'evidence' => 'Detected database product: ' . $databaseProduct,
                    'impact' => 'Lifecycle status for this database family cannot be determined automatically.',
                    'remediation' => 'Use a supported database family or provide explicit lifecycle metadata.',
                    'limitations' => 'Automated lookup currently supports official MySQL and MariaDB channels only.',
                ]
            );
        }

        return $rows;
    }
}


<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\ArrayData;

/**
 * Shared escaped health table for local analysis, monitoring and attempt history.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class HealthReportRenderer
{
    use Injectable;

    /** Renders plain validated data without running checks or source lookups. */
    public function render(array $report): string
    {
        $rows = [];
        foreach (HealthReport::singleton()->normalize($report) as $row) {
            $rows[] = $row + ['label' => HealthStatus::label($row['status'])];
        }
        return (string) ArrayData::create([
            'Rows' => $rows,
            'Title' => _t(__CLASS__ . '.TITLE', 'System health'),
            'Empty' => _t(__CLASS__ . '.EMPTY', 'No health results available for this attempt.'),
        ])->renderWith('Clesson/Silverstripe/DeveloperTools/HealthReport');
    }
}

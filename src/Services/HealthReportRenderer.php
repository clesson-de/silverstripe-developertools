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
        foreach (HealthReport::singleton()->normalize($report, true) as $row) {
            $detail = str_contains((string) $row['id'], '.issue.');
            $group = $detail ? explode('.issue.', (string) $row['id'], 2)[0] : (string) $row['id'];
            $rows[] = $row + [
                'label' => HealthStatus::label($row['status']),
                'state_label' => strtoupper(str_replace('_', ' ', $row['state'] ?? '')),
                'severity_label' => strtoupper((string) ($row['severity'] ?? '')),
                'priority_label' => strtoupper((string) ($row['priority'] ?? '')),
                'is_detail' => $detail,
                'row_class' => $detail ? 'monitoring-health__row monitoring-health__row--detail' : 'monitoring-health__row',
                'group_key' => preg_replace('/[^a-z0-9_.-]/i', '', $group) ?? '',
            ];
        }
        return (string) ArrayData::create([
            'Rows' => $rows,
            'Title' => _t(__CLASS__ . '.TITLE', 'System health'),
            'Empty' => _t(__CLASS__ . '.EMPTY', 'No health results available for this attempt.'),
        ])->renderWith('Clesson/Silverstripe/DeveloperTools/HealthReport');
    }
}

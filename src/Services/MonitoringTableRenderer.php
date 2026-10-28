<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Models\MonitoredWebsite;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoringCheck;
use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\ArrayData;

/**
 * Renders cached website assessments as a plain, escaped HTML table.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class MonitoringTableRenderer
{
    use Injectable;

    /** Uses the latest saved inventory and cached comparisons without network requests. */
    public function renderActionRequired(MonitoredWebsite $website): string
    {
        return $this->signal(_t(__CLASS__ . '.VERSIONS', 'Versions'), $this->versionIndicator($website))
            . $this->signal(_t(__CLASS__ . '.HEALTH', 'Healthchecks'), $this->healthIndicator($website));
    }

    /** Makes the independent meaning of the two icons visible without hovering. */
    private function signal(string $label, string $icon): string
    {
        return '<span class="monitoring-signal"><small>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</small>' . $icon . '</span>';
    }

    /** Separately signals health errors, regardless of the version threshold. */
    private function healthIndicator(MonitoredWebsite $website): string
    {
        $check = $website->Checks()->first();
        $status = $check ? $check->getHealthStatus() : HealthStatus::UNKNOWN;
        $style = match ($status) {
            HealthStatus::OK => 'current', HealthStatus::WARNING => 'update',
            HealthStatus::ERROR => 'failed', default => 'unknown',
        };
        $label = _t(__CLASS__ . '.HEALTH', 'Healthchecks') . ': ' . HealthStatus::label($status);
        $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        return '<span class="monitoring-status--' . $style . '" role="img" aria-label="' . $label
            . '" title="' . $label . '"><span class="monitoring-status-icon" aria-hidden="true"></span></span>';
    }

    /** The original strict-majority indicator remains specific to versions. */
    private function versionIndicator(MonitoredWebsite $website): string
    {
        $snapshot = $website->LatestSnapshot();
        $required = $snapshot->exists()
            ? ReleaseVersionService::singleton()->actionRequired($snapshot->getInventory()) : null;
        $status = $required === null ? 'unknown' : ($required ? 'update' : 'current');
        $label = match ($required) {
            true => _t(__CLASS__ . '.ACTION_REQUIRED', 'Action required: more than 50% of assessable components are outdated.'),
            false => _t(__CLASS__ . '.NO_ACTION_REQUIRED', 'No action required: at most 50% of assessable components are outdated.'),
            null => _t(__CLASS__ . '.NOT_ASSESSABLE', 'No assessable components available.'),
        };
        $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        return '<span class="monitoring-status--' . $status . '" role="img" aria-label="' . $label
            . '" title="' . $label . '"><span class="monitoring-status-icon" aria-hidden="true"></span></span>';
    }

    /** Does not call a customer website or release provider while rendering. */
    public function render(MonitoredWebsite $website): string
    {
        $check = $website->Checks()->first();
        return $this->renderVersions($website)
            . HealthReportRenderer::create()->render($check ? $check->getHealthChecks() : []);
    }

    /** Renders inventory assessments independently of the latest attempt's health. */
    private function renderVersions(MonitoredWebsite $website): string
    {
        $snapshot = $website->LatestSnapshot();
        $rows = $snapshot->exists() ? ReleaseVersionService::singleton()->assess($snapshot->getInventory()) : [];
        $data = [
            'Rows' => $rows, 'Error' => MonitoringCheck::errorLabel((string) $website->LastError),
            'Empty' => _t(__CLASS__ . '.EMPTY', 'No successful inventory has been recorded.'),
            'Disclaimer' => _t(__CLASS__ . '.DISCLAIMER', 'Latest stable does not mean compatible. Change timestamps indicate detection, not deployment. Source times are UTC.'),
            'ItemTitle' => _t(__CLASS__ . '.ITEM', 'Item'),
            'CurrentTitle' => _t(__CLASS__ . '.CURRENT', 'Current version'),
            'LatestTitle' => _t(__CLASS__ . '.LATEST', 'Latest stable version'),
            'StatusTitle' => _t(__CLASS__ . '.STATUS', 'Status'),
        ];
        return (string) ArrayData::create($data)->renderWith('Clesson/Silverstripe/DeveloperTools/MonitoringTable');
    }
}

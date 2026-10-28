<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Forms;

use Clesson\Silverstripe\DeveloperTools\Constants\MonitoringField as F;
use Clesson\Silverstripe\DeveloperTools\Constants\MonitoringStatus;
use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoringCheck;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\GridField\GridFieldDataColumns;

/**
 * Read-only check attempt history with bounded pagination.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Forms
 */
class GridFieldConfig_MonitoringChecks extends GridFieldConfig_RecordViewer
{
    /** Creates the check history configuration. */
    public function __construct()
    {
        parent::__construct(20);
        $this->getComponentByType(GridFieldDataColumns::class)->setFieldFormatting([
            F::STATUS => static fn($value) => MonitoringStatus::label($value),
            F::ERROR_MESSAGE => static fn($value) => MonitoringCheck::errorLabel((string) $value),
            F::HEALTH_STATUS => static fn($value) => HealthStatus::label($value),
        ]);
    }
}

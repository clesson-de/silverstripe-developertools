<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Forms;

use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;

/**
 * Read-only historical inventories, without create, edit or delete controls.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Forms
 */
class GridFieldConfig_MonitoringSnapshots extends GridFieldConfig_RecordViewer
{
    /** Creates paginated historical snapshot views. */
    public function __construct()
    {
        parent::__construct(20);
    }
}

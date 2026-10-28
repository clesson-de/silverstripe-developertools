<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Panels;

use Clesson\Silverstripe\Dashboard\Panels\DashboardPanel;
use Clesson\Silverstripe\DeveloperTools\Admins\DeveloperToolsAdmin;

/**
 * Dashboard panel displaying local project versions for CMS administrators.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Panels
 */
class ProjectInformationPanel extends DashboardPanel
{
    /** @inheritdoc */
    public function getTitle(): string
    {
        return _t(__CLASS__ . '.TITLE', 'Project Information');
    }

    /** @inheritdoc */
    public function getResourceURL(): string
    {
        return DeveloperToolsAdmin::singleton()->Link('projectInformationPanel');
    }
}

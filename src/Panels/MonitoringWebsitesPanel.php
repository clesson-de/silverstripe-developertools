<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Panels;

use Clesson\Silverstripe\Dashboard\Panels\DashboardPanel;
use Clesson\Silverstripe\DeveloperTools\Admins\DeveloperToolsAdmin;
use SilverStripe\Core\Manifest\ModuleResourceLoader;

/**
 * Lists configured customer websites with their cached action indicators.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Panels
 */
class MonitoringWebsitesPanel extends DashboardPanel
{
    /** @inheritdoc */
    public function getTitle(): string
    {
        return _t(__CLASS__ . '.TITLE', 'Website Monitoring');
    }

    /** @inheritdoc */
    public function getResourceURL(): string
    {
        return DeveloperToolsAdmin::singleton()->Link('monitoringWebsitesPanel');
    }

    /** Loads indicator styling when used outside the developer tools dashboard. */
    public function getCSS(): ?string
    {
        return ModuleResourceLoader::resourceURL('clesson-de/silverstripe-developertools:client/admin/dist/bundle.css');
    }
}

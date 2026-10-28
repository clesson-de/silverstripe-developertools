<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Forms;

use Clesson\Silverstripe\DeveloperTools\Constants\ProjectInformationField;
use Clesson\Silverstripe\DeveloperTools\Helpers\ComposerLockReader;
use Clesson\Silverstripe\DeveloperTools\Helpers\RoutesReader;
use Clesson\Silverstripe\DeveloperTools\Services\ProjectInformationService;
use Clesson\Silverstripe\DeveloperTools\Services\HealthCheckService;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReportRenderer;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;

/**
 * Builds the existing analysis tools within a nested CMS tab set.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Forms
 */
class AnalysisTabSet
{
    /**
     * Returns the project information, Composer packages and routes tabs.
     */
    public function build(): TabSet
    {
        return TabSet::create(
            'Analysis',
            _t(__CLASS__ . '.TAB_ANALYSIS', 'Analysis'),
            $this->buildProjectInformationTab(),
            $this->buildComposerPackagesTab(),
            $this->buildRoutesTab()
        );
    }

    /**
     * Builds the read-only project information tab for CMS administrators.
     */
    private function buildProjectInformationTab(): Tab
    {
        $tab = Tab::create(
            'ProjectInformation',
            _t(__CLASS__ . '.TAB_PROJECT_INFORMATION', 'Project Information')
        );
        foreach (ProjectInformationService::singleton()->getInformation() as $key => $value) {
            /** @var ReadonlyField $informationField */
            $informationField = ReadonlyField::create($key, ProjectInformationField::label($key), $value);
            $tab->push($informationField);
        }
        $tab->push(LiteralField::create('SystemHealth',
            HealthReportRenderer::create()->render(HealthCheckService::singleton()->collect())));
        return $tab;
    }

    /**
     * Builds the "Composer Packages" tab.
     *
     * @return Tab
     */
    private function buildComposerPackagesTab(): Tab
    {
        $packages = ComposerLockReader::getPackages();

        /** @var GridFieldDataColumns $dataColumns */
        $dataColumns = GridFieldDataColumns::create();
        $dataColumns->setDisplayFields([
            'PackageName' => _t(__CLASS__ . '.COLUMN_NAME', 'Package'),
            'Version'     => _t(__CLASS__ . '.COLUMN_VERSION', 'Version'),
            'Type'        => _t(__CLASS__ . '.COLUMN_TYPE', 'Type'),
            'License'     => _t(__CLASS__ . '.COLUMN_LICENSE', 'License'),
            'Description' => _t(__CLASS__ . '.COLUMN_DESCRIPTION', 'Description'),
        ]);

        $gridConfig = GridFieldConfig::create();
        $gridConfig->addComponent(GridFieldToolbarHeader::create());
        $gridConfig->addComponent(GridFieldSortableHeader::create());
        $gridConfig->addComponent($dataColumns);
        $gridConfig->addComponent(GridFieldPaginator::create(50));

        /** @var GridField $packagesField */
        $packagesField = GridField::create(
            'Packages',
            '',
            $packages,
            $gridConfig
        );

        $placeholder = _t(__CLASS__ . '.SEARCH_PLACEHOLDER', 'Search by name or description…');

        /** @var LiteralField $searchField */
        $searchField = LiteralField::create('PackagesSearch', '
            <div class="developer-tools-search-wrap" style="margin-bottom:12px">
                <input
                    type="text"
                    class="developer-tools-search text no-change-track"
                    placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"
                    style="max-width:400px;width:100%"
                />
            </div>
            <script>
            (function () {
                function initSearch() {
                    var input = document.querySelector(\'.developer-tools-search\');
                    if (!input || input._dtInitialized) return;
                    input._dtInitialized = true;

                    input.addEventListener(\'input\', function () {
                        var q = this.value.toLowerCase();
                        var grid = this.closest(\'.tab\') || document;
                        var rows = grid.querySelectorAll(\'.grid-field__table tbody tr\');
                        rows.forEach(function (row) {
                            row.style.display = (q === \'\' || row.textContent.toLowerCase().includes(q)) ? \'\' : \'none\';
                        });
                    });
                }

                if (document.readyState === \'loading\') {
                    document.addEventListener(\'DOMContentLoaded\', initSearch);
                } else {
                    initSearch();
                }

                // Re-attach after PJAX replacements
                document.addEventListener(\'ajaxComplete\', initSearch);
            })();
            </script>
        ');

        return Tab::create(
            'ComposerPackages',
            _t(__CLASS__ . '.TAB_COMPOSER_PACKAGES', 'Composer Packages'),
            $searchField,
            $packagesField
        );
    }

    /**
     * Builds the "Routes" tab listing all Director rules.
     *
     * @return Tab
     */
    private function buildRoutesTab(): Tab
    {
        $routes = RoutesReader::getRoutes();

        /** @var GridFieldDataColumns $dataColumns */
        $dataColumns = GridFieldDataColumns::create();
        $dataColumns->setDisplayFields([
            'RoutePattern' => _t(__CLASS__ . '.ROUTES_COLUMN_PATTERN', 'Route'),
            'Controller'   => _t(__CLASS__ . '.ROUTES_COLUMN_CONTROLLER', 'Controller'),
            'Action'       => _t(__CLASS__ . '.ROUTES_COLUMN_ACTION', 'Action'),
            'Package'      => _t(__CLASS__ . '.ROUTES_COLUMN_PACKAGE', 'Package'),
        ]);

        $gridConfig = GridFieldConfig::create();
        $gridConfig->addComponent(GridFieldToolbarHeader::create());
        $gridConfig->addComponent(GridFieldSortableHeader::create());
        $gridConfig->addComponent($dataColumns);
        $gridConfig->addComponent(GridFieldPaginator::create(50));

        /** @var GridField $routesField */
        $routesField = GridField::create(
            'Routes',
            '',
            $routes,
            $gridConfig
        );

        $placeholder = _t(__CLASS__ . '.ROUTES_SEARCH_PLACEHOLDER', 'Search by route, controller or package…');

        /** @var LiteralField $searchField */
        $searchField = LiteralField::create('RoutesSearch', '
            <div class="developer-tools-search-wrap" style="margin-bottom:12px">
                <input
                    type="text"
                    class="developer-tools-routes-search text no-change-track"
                    placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"
                    style="max-width:400px;width:100%"
                />
            </div>
            <script>
            (function () {
                function initRoutesSearch() {
                    var input = document.querySelector(\'.developer-tools-routes-search\');
                    if (!input || input._dtInitialized) return;
                    input._dtInitialized = true;

                    input.addEventListener(\'input\', function () {
                        var q = this.value.toLowerCase();
                        var grid = this.closest(\'.tab\') || document;
                        var rows = grid.querySelectorAll(\'.grid-field__table tbody tr\');
                        rows.forEach(function (row) {
                            row.style.display = (q === \'\' || row.textContent.toLowerCase().includes(q)) ? \'\' : \'none\';
                        });
                    });
                }

                if (document.readyState === \'loading\') {
                    document.addEventListener(\'DOMContentLoaded\', initRoutesSearch);
                } else {
                    initRoutesSearch();
                }

                document.addEventListener(\'ajaxComplete\', initRoutesSearch);
            })();
            </script>
        ');

        return Tab::create(
            'Routes',
            _t(__CLASS__ . '.TAB_ROUTES', 'Routes'),
            $searchField,
            $routesField
        );
    }
}

<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Admins;

use Clesson\Silverstripe\DeveloperTools\Helpers\ComposerLockReader;
use Clesson\Silverstripe\DeveloperTools\Helpers\RoutesReader;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

/**
 * CMS admin section providing developer tools.
 *
 * Currently includes:
 * - Composer Packages: lists all installed packages from composer.lock.
 *
 * Only accessible to CMS administrators.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Admins
 */
class DeveloperToolsAdmin extends LeftAndMain
{
    /**
     * @config
     */
    private static string $url_segment = 'developer-tools';

    /**
     * @config
     */
    private static string $menu_title = 'Developer Tools';

    /**
     * @config
     */
    private static float $menu_priority = -200;

    /**
     * @config
     */
    private static string $menu_icon_class = 'font-icon-list';

    /**
     * @config
     */
    private static string $required_permission_codes = 'ADMIN';

    /**
     * @inheritdoc
     */
    private static array $allowed_actions = [
        'index',
    ];

    /**
     * Only administrators may access this section.
     *
     * @param \SilverStripe\Security\Member|null $member
     * @return bool
     */
    public function canView($member = null): bool
    {
        if ($member === null) {
            $member = Security::getCurrentUser();
        }

        return Permission::check('ADMIN', 'any', $member);
    }

    /**
     * Returns the edit form containing the developer tools TabSet.
     *
     * @param int|null       $id     Not used.
     * @param FieldList|null $fields Not used.
     * @return Form
     */
    public function getEditForm($id = null, $fields = null): Form
    {
        $form = Form::create(
            $this,
            'EditForm',
            FieldList::create(
                TabSet::create(
                    'Root',
                    Tab::create(
                        'Main',
                        _t(__CLASS__ . '.TAB_MAIN', 'Developer Tools'),
                        TabSet::create(
                            'ToolsTabSet',
                            $this->buildComposerPackagesTab(),
                            $this->buildRoutesTab()
                        )
                    )
                )
            ),
            FieldList::create()
        );

        $form->setHTMLID('Form_EditForm');
        $form->addExtraClass('cms-edit-form');
        $form->setTemplate($this->getTemplatesWithSuffix('_EditForm'));
        $form->addExtraClass('center ss-tabset cms-tabset ' . $this->BaseCSSClasses());
        $form->setAttribute('data-pjax-fragment', 'CurrentForm');

        return $form;
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
                    if (!input) return;

                    input.addEventListener(\'input\', function () {
                        var q = this.value.toLowerCase();
                        var rows = document.querySelectorAll(\'#Form_EditForm .grid-field__table tbody tr\');
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


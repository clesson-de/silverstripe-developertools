<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Admins;

use Clesson\Silverstripe\DeveloperTools\Helpers\ComposerLockReader;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\TextField;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

/**
 * CMS admin section listing all installed Composer packages and their versions.
 *
 * Reads directly from composer.lock — no database required.
 * Only accessible to CMS administrators.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Admins
 */
class ComposerPackagesAdmin extends LeftAndMain
{
    /**
     * @config
     */
    private static string $url_segment = 'composer-packages';

    /**
     * @config
     */
    private static string $menu_title = 'Packages';

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
     * Returns the edit form containing the packages GridField.
     *
     * @param int|null        $id     Not used.
     * @param FieldList|null  $fields Not used.
     * @return Form
     */
    public function getEditForm($id = null, $fields = null): Form
    {
        $search = (string) ($this->getRequest()->getVar('q') ?? '');
        $packages = ComposerLockReader::getPackages($search);

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

        /** @var TextField $searchField */
        $searchField = TextField::create('q', '')
            ->setAttribute('placeholder', _t(__CLASS__ . '.SEARCH_PLACEHOLDER', 'Search by name or description…'))
            ->setAttribute('value', $search)
            ->addExtraClass('composer-packages-search no-change-track');

        $form = Form::create(
            $this,
            'EditForm',
            FieldList::create($searchField, $packagesField),
            FieldList::create()
        );

        $form->setHTMLID('Form_EditForm');
        $form->addExtraClass('cms-edit-form');
        $form->setTemplate($this->getTemplatesWithSuffix('_EditForm'));
        $form->addExtraClass('center ss-tabset cms-tabset ' . $this->BaseCSSClasses());
        $form->setAttribute('data-pjax-fragment', 'CurrentForm');
        $form->setFormMethod('GET');

        return $form;
    }
}


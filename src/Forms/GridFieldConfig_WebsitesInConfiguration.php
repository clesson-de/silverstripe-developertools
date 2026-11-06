<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Forms;

use Clesson\Silverstripe\Constants\GridFieldToolbarLayout;
use Clesson\Silverstripe\DeveloperTools\Constants\MonitoringField;
use Clesson\Silverstripe\Forms\GridField\GridFieldSearch;
use Clesson\Silverstripe\Forms\GridField\GridFieldToolbar;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoredWebsite;
use Clesson\Silverstripe\DeveloperTools\Forms\GridField\GridFieldAction_ToggleActive;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldFilterHeader;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\ORM\FieldType\DBField;

/**
 * Editable website configuration without credential columns or deletion actions.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Forms
 */
class GridFieldConfig_WebsitesInConfiguration extends GridFieldConfig_RecordEditor
{
    /** Configures website details and system-managed check times. */
    public function __construct()
    {
        parent::__construct(20);
        $this->removeComponentsByType(GridFieldDeleteAction::class);
        $this->removeComponentsByType(GridFieldFilterHeader::class);
        $this->addToolbar();
        $columns = [];
        foreach (['Name', 'BaseURL', 'FirstCheckedAt', 'LastCheckedAt', 'LastSuccessfulCheckAt'] as $field) {
            $columns[$field] = [
                'title' => MonitoredWebsite::singleton()->fieldLabel($field),
                'callback' => static fn($record) => DBField::create_field('Varchar', $field === 'Active'
                    ? ($record->Active ? _t(__CLASS__ . '.ENABLED', 'Enabled') : _t(__CLASS__ . '.DISABLED', 'Disabled'))
                    : (string) $record->$field),
            ];
        }
        $this->getComponentByType(GridFieldDataColumns::class)->setDisplayFields($columns);
        $this->addComponent(GridFieldAction_ToggleActive::create());
    }

    /** Replaces column filters with the shared search while retaining the existing creation action. */
    private function addToolbar(): void
    {
        $toolbar = GridFieldToolbar::install($this);
        $search = $toolbar->addItem('search', GridFieldToolbarLayout::LEFT, GridFieldToolbarLayout::CONTENT);
        $this->addComponent(
            GridFieldSearch::create([MonitoringField::NAME, MonitoringField::BASE_URL], $search->getFragment()),
            GridFieldDataColumns::class
        );
        $this->removeComponentsByType(GridFieldAddNewButton::class);
        $this->addComponent(GridFieldAddNewButton::create('buttons-before-right'));
    }
}

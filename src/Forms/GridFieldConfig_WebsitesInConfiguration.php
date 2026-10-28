<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Forms;

use Clesson\Silverstripe\DeveloperTools\Models\MonitoredWebsite;
use Clesson\Silverstripe\DeveloperTools\Forms\GridField\GridFieldAction_ToggleActive;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
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
}

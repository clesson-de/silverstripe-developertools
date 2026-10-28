<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Forms;

use Clesson\Silverstripe\DeveloperTools\Admins\DeveloperToolsAdmin;
use Clesson\Silverstripe\DeveloperTools\Constants\MonitoringStatus;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoredWebsite;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringTableRenderer;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\ORM\FieldType\DBField;

/**
 * Read-only website list with accessible controls for inline expansion and checks.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Forms
 */
class GridFieldConfig_WebsitesInInfo extends GridFieldConfig
{
    /** Creates the overview without nested editing or selection behavior. */
    public function __construct()
    {
        parent::__construct();
        $this->addComponent(GridFieldToolbarHeader::create());
        $this->addComponent(GridFieldSortableHeader::create());
        $this->addComponent($this->columns());
        $this->addComponent(GridFieldPaginator::create(20));
    }

    /** Defines escaped informational columns and explicit action buttons. */
    private function columns(): GridFieldDataColumns
    {
        $columns = [];
        foreach (['Name', 'BaseURL', 'ActionRequired', 'LastStatus', 'LastCheckedAt', 'LastChangeAt'] as $field) {
            $columns[$field] = $field === 'ActionRequired' ? $this->actionRequiredColumn() : $this->informationColumn($field);
        }
        $columns['MonitoringActions'] = [
            'title' => _t(__CLASS__ . '.ACTIONS', 'Actions'),
            'callback' => fn($record) => DBField::create_field('HTMLText', $this->buttons((int) $record->ID)),
        ];
        return GridFieldDataColumns::create()->setDisplayFields($columns);
    }

    /** Formats stored website fields without exposing unescaped values. */
    private function informationColumn(string $field): array
    {
        return [
            'title' => MonitoredWebsite::singleton()->fieldLabel($field),
            'callback' => static fn($record) => DBField::create_field(
                'Varchar', $field === 'LastStatus' ? MonitoringStatus::label($record->LastStatus) : (string) $record->$field
            ),
        ];
    }

    /** Renders an accessible aggregate icon instead of the activation state. */
    private function actionRequiredColumn(): array
    {
        return [
            'title' => _t(__CLASS__ . '.ACTION_REQUIRED', 'Action required'),
            'callback' => static fn($record) => DBField::create_field(
                'HTMLText', MonitoringTableRenderer::create()->renderActionRequired($record)
            ),
        ];
    }

    /** Buttons are not submit controls and never embed a customer credential. */
    private function buttons(int $id): string
    {
        $admin = DeveloperToolsAdmin::singleton();
        return '<button type="button" class="btn btn-secondary" data-monitoring-expand="'
            . htmlspecialchars($admin->Link('monitoringDetails/' . $id), ENT_QUOTES, 'UTF-8')
            . '" aria-expanded="false">' . htmlspecialchars(_t(__CLASS__ . '.DETAILS', 'Details'), ENT_QUOTES, 'UTF-8')
            . '</button> <button type="button" class="btn btn-primary" data-monitoring-check="'
            . htmlspecialchars($admin->Link('monitoringCheck/' . $id), ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars(_t(__CLASS__ . '.CHECK', 'Check now'), ENT_QUOTES, 'UTF-8') . '</button>';
    }
}

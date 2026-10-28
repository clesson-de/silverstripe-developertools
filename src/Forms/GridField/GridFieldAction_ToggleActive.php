<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Forms\GridField;

use Clesson\Silverstripe\DeveloperTools\Models\MonitoredWebsite;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_FormAction;

/**
 * Enables or disables monitoring from its administrator configuration GridField.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Forms
 */
class GridFieldAction_ToggleActive implements GridField_ColumnProvider, GridField_ActionProvider
{
    use Injectable;

    /** Action and column are isolated from other GridField components. */
    private const ACTION = 'togglemonitoringactive';
    /** Toggle column name. */
    private const COLUMN = 'MonitoringActive';

    /** Adds the toggle column. */
    public function augmentColumns($gridField, &$columns): void
    {
        if (!in_array(self::COLUMN, $columns, true)) {
            $columns[] = self::COLUMN;
        }
    }

    /** Returns the column handled by this component. */
    public function getColumnsHandled($gridField): array
    {
        return [self::COLUMN];
    }

    /** Uses the model's translated active label. */
    public function getColumnMetadata($gridField, $columnName): array
    {
        return ['title' => MonitoredWebsite::singleton()->fieldLabel('Active')];
    }

    /** Keeps the button column compact. */
    public function getColumnAttributes($gridField, $record, $columnName): array
    {
        return ['class' => 'grid-field__col-compact'];
    }

    /** Renders a standard CSRF-protected GridField action, without a row edit click. */
    public function getColumnContent($gridField, $record, $columnName): string
    {
        $label = $record->Active ? _t(__CLASS__ . '.ENABLED', 'Enabled') : _t(__CLASS__ . '.DISABLED', 'Disabled');
        /** @var GridField_FormAction $action */
        $action = GridField_FormAction::create(
            $gridField, 'MonitoringActive' . $record->ID, $label, self::ACTION, ['RecordID' => $record->ID]
        );
        $action->addExtraClass('btn btn-secondary no-change-track');
        return (string) $action->Field();
    }

    /** Declares the supported GridField action. */
    public function getActions($gridField): array
    {
        return [self::ACTION];
    }

    /** Rechecks permissions and scopes the record to the current GridField. */
    public function handleAction(GridField $gridField, $actionName, $arguments, $data): void
    {
        $record = $gridField->getList()->byID((int) ($arguments['RecordID'] ?? 0));
        if ($actionName !== self::ACTION || !$record) {
            throw new HTTPResponse_Exception(_t(__CLASS__ . '.NOT_FOUND', 'Website not found.'), 404);
        }
        if (!$record->canEdit()) {
            throw new HTTPResponse_Exception(_t(__CLASS__ . '.ACCESS_DENIED', 'Access denied.'), 403);
        }
        $record->Active = !$record->Active;
        $record->write();
    }
}

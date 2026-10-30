<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Models;

use Clesson\Silverstripe\DeveloperTools\Constants\ToastEnvironment;
use Clesson\Silverstripe\DeveloperTools\Constants\ToastField as F;
use Clesson\Silverstripe\DeveloperTools\Constants\ToastHorizontalPosition;
use Clesson\Silverstripe\DeveloperTools\Constants\ToastType;
use Clesson\Silverstripe\DeveloperTools\Constants\ToastVerticalPosition;
use Clesson\Silverstripe\DeveloperTools\Helpers\ToastIPRanges;
use Clesson\Silverstripe\Markdown\FieldTypes\DBMarkdown;
use Clesson\Silverstripe\Markdown\Forms\MarkdownEditorField;
use DomainException;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\ListboxField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\Security\Group;
use SilverStripe\Security\Permission;

/**
 * Administrator-managed frontend notice; delivery does not expose CMS records.
 *
 * @property string $Title
 * @property string $Type
 * @property string $Environment
 * @property string $IPRanges
 * @property DBMarkdown $Message
 * @property int $AutoHideSeconds
 * @property bool $Removable
 * @property string $HorizontalPosition
 * @property string $VerticalPosition
 * @property bool $Active
 * @property string|null $StartDate
 * @property string|null $EndDate
 * @property int $SettingsID
 * @property-read string $Preview
 * @method DeveloperToolsConfig Settings()
 * @method ManyManyList|Group[] Groups()
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Models
 */
class ToastMessage extends DataObject
{
    /** @config */
    private static string $table_name = 'DeveloperTools_ToastMessage';
    /** @config */
    private static string $default_sort = 'Created ASC, ID ASC';
    /** @config */
    private static string $general_search_field = F::MESSAGE_PLAIN;
    /** @config */
    private static array $db = [
        F::TITLE => 'Varchar(200)',
        F::TYPE => 'Varchar(7)',
        F::ENVIRONMENT => 'Varchar(4)',
        F::IP_RANGES => 'Text',
        F::MESSAGE => DBMarkdown::class,
        F::AUTO_HIDE_SECONDS => 'Int',
        F::REMOVABLE => 'Boolean',
        F::HORIZONTAL_POSITION => 'Varchar(6)',
        F::VERTICAL_POSITION => 'Varchar(6)',
        F::ACTIVE => 'Boolean',
        F::START_DATE => 'Datetime',
        F::END_DATE => 'Datetime',
    ];
    /** @config */
    private static array $has_one = [F::SETTINGS => DeveloperToolsConfig::class];
    /** @config */
    private static array $many_many = [F::GROUPS => Group::class];
    /** @config */
    private static array $summary_fields = [
        F::TITLE, F::PREVIEW, F::TYPE, F::ENVIRONMENT, F::ACTIVE, F::IP_RANGES,
        F::HORIZONTAL_POSITION, F::VERTICAL_POSITION, F::AUTO_HIDE_SECONDS,
    ];
    /** @config */
    private static array $searchable_fields = [F::TITLE, F::MESSAGE_PLAIN, F::TYPE, F::ENVIRONMENT, F::ACTIVE];

    /** New messages are visible, dismissible information notices in the upper right. */
    public function populateDefaults(): void
    {
        parent::populateDefaults();
        $this->Type = ToastType::INFO;
        $this->Environment = ToastEnvironment::ALL;
        $this->AutoHideSeconds = 5;
        $this->Removable = true;
        $this->HorizontalPosition = ToastHorizontalPosition::RIGHT;
        $this->VerticalPosition = ToastVerticalPosition::TOP;
        $this->Active = true;
    }

    /** Validates source content and all server-side delivery settings. */
    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->dbObject(F::MESSAGE)->exists()) {
            $result->addFieldError(F::MESSAGE, _t(Form::class . '.FIELDISREQUIRED', '{name} is required', [
                'name' => $this->fieldLabel(F::MESSAGE),
            ]));
        }
        $this->validateChoices($result);
        $this->validateDelivery($result);
        $this->validateDisplayPeriod($result);
        return $result;
    }

    /** Rejects a reversed display period while allowing either bound to remain empty. */
    private function validateDisplayPeriod(ValidationResult $result): void
    {
        if ($this->StartDate && $this->EndDate && $this->EndDate < $this->StartDate) {
            $result->addFieldError(F::END_DATE, _t(__CLASS__ . '.INVALID_PERIOD',
                'The end date must not be before the start date.'));
        }
    }

    /** Rejects forged choice values rather than using invalid CSS or delivery settings. */
    private function validateChoices(ValidationResult $result): void
    {
        foreach ($this->choiceFields() as $name => $options) {
            if (!array_key_exists((string) $this->getField($name), $options)) {
                $result->addFieldError($name, _t(__CLASS__ . '.INVALID_CHOICE', 'Select a valid option.'));
            }
        }
    }

    /** Shares IP validation with request-time selection; zero disables automatic dismissal. */
    private function validateDelivery(ValidationResult $result): void
    {
        $seconds = filter_var($this->getField(F::AUTO_HIDE_SECONDS), FILTER_VALIDATE_INT);
        if ($seconds === false || $seconds < 0 || $seconds > 2147483647) {
            $result->addFieldError(F::AUTO_HIDE_SECONDS, _t(__CLASS__ . '.INVALID_DURATION', 'Enter whole seconds between 0 and 2147483647.'));
        }
        try {
            ToastIPRanges::parse((string) $this->IPRanges);
        } catch (DomainException $exception) {
            $result->addFieldError(F::IP_RANGES, _t(__CLASS__ . '.INVALID_IP_RANGES', 'Enter valid IPv4/IPv6 addresses, CIDR networks or ordered start-end ranges.'));
        }
    }

    /** Returns a bounded plaintext overview without rendering Markdown in a table cell. */
    public function getPreview(): string
    {
        return mb_strimwidth((string) $this->dbObject(F::MESSAGE)->getPlain(), 0, 100, '...');
    }

    /** Uses translated model and shared metadata labels. */
    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), F::options(), [
            'ID' => _t('Clesson\Silverstripe\DeveloperTools\Common.ID', 'ID'),
            'Created' => _t('Clesson\Silverstripe\DeveloperTools\Common.CREATED', 'Created'),
            'LastEdited' => _t('Clesson\Silverstripe\DeveloperTools\Common.LAST_EDITED', 'Last edited'),
        ]);
    }

    /** Uses the shared Markdown editor and constrained selection fields. */
    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(F::SETTINGS . 'ID');
        /** @var MarkdownEditorField $messageField */
        $messageField = MarkdownEditorField::create(F::MESSAGE, $this->fieldLabel(F::MESSAGE));
        $messageField->setRows(8);
        $fields->replaceField(F::MESSAGE, $messageField);
        $fields->dataFieldByName(F::TITLE)?->setDescription(_t(__CLASS__ . '.TITLE_HELP',
            'Optional plain text. HTML and Markdown are not rendered.'));
        $this->addChoiceFields($fields);
        $this->groupPositionFields($fields);
        $this->configureDeliveryFields($fields);
        $this->configureGroupFilter($fields);
        $this->organiseTabs($fields);
        return $fields;
    }

    /** Separates content, visual behaviour and audience restrictions without recreating editor fields. */
    private function organiseTabs(FieldList $fields): void
    {
        $fields->fieldByName('Root.Main')->setTitle(_t(__CLASS__ . '.TAB_CONTENT', 'Content'));
        $fields->findOrMakeTab('Root.Appearance', _t(__CLASS__ . '.TAB_APPEARANCE', 'Appearance'));
        $fields->findOrMakeTab('Root.Filters', _t(__CLASS__ . '.TAB_FILTERS', 'Filters'));
        $this->moveFields($fields, 'Appearance', [F::TYPE, F::POSITION, F::AUTO_HIDE_SECONDS, F::REMOVABLE]);
        $this->moveFields($fields, 'Filters', [F::ENVIRONMENT, F::IP_RANGES, F::GROUPS, F::START_DATE, F::END_DATE]);
    }

    /** Moves existing fields in the requested order, including composite field groups. */
    private function moveFields(FieldList $fields, string $tab, array $names): void
    {
        foreach ($names as $name) {
            $field = $fields->fieldByName('Root.Main.' . $name);
            $fields->removeByName($name);
            $fields->addFieldToTab('Root.' . $tab, $field);
        }
    }

    /** Selects existing groups without exposing group creation or permission management. */
    private function configureGroupFilter(FieldList $fields): void
    {
        $fields->removeByName(F::GROUPS);
        /** @var ListboxField $groupsField */
        $groupsField = ListboxField::create(F::GROUPS, $this->fieldLabel(F::GROUPS), Group::get()->map('ID', 'Title'));
        $groupsField->setDescription(_t(__CLASS__ . '.GROUPS_HELP',
            'Leave empty for everyone. Otherwise, only signed-in members of at least one selected group (including subgroups) can see the message. Other filters still apply.'));
        $fields->addFieldToTab('Root.Main', $groupsField, F::IP_RANGES);
    }

    /** Places the two position selectors side by side without changing their persisted values. */
    private function groupPositionFields(FieldList $fields): void
    {
        $horizontalPositionField = $fields->dataFieldByName(F::HORIZONTAL_POSITION);
        $verticalPositionField = $fields->dataFieldByName(F::VERTICAL_POSITION);
        $fields->removeByName(F::VERTICAL_POSITION);
        /** @var FieldGroup $positionField */
        $positionField = FieldGroup::create($horizontalPositionField, $verticalPositionField);
        $positionField->setName(F::POSITION)->setTitle($this->fieldLabel(F::POSITION));
        $fields->replaceField(F::HORIZONTAL_POSITION, $positionField);
    }

    /** Provides the same permitted values to the editor and validation. */
    private function choiceFields(): array
    {
        return [
            F::TYPE => ToastType::options(),
            F::ENVIRONMENT => ToastEnvironment::options(),
            F::HORIZONTAL_POSITION => ToastHorizontalPosition::options(),
            F::VERTICAL_POSITION => ToastVerticalPosition::options(),
        ];
    }

    /** Replaces free text scaffolded for the persisted varchar selections. */
    private function addChoiceFields(FieldList $fields): void
    {
        foreach ($this->choiceFields() as $name => $options) {
            /** @var DropdownField $choiceField */
            $choiceField = DropdownField::create($name, $this->fieldLabel($name), $options);
            $fields->replaceField($name, $choiceField);
        }
    }

    /** Explains address syntax and constrains whole-second input in the editor. */
    private function configureDeliveryFields(FieldList $fields): void
    {
        /** @var NumericField $autoHideSecondsField */
        $autoHideSecondsField = NumericField::create(F::AUTO_HIDE_SECONDS, $this->fieldLabel(F::AUTO_HIDE_SECONDS));
        $autoHideSecondsField->setHTML5(true)->setAttribute('min', 0)->setAttribute('max', 2147483647)->setAttribute('step', 1);
        $autoHideSecondsField->setDescription(_t(__CLASS__ . '.DURATION_HELP', 'Seconds until automatic dismissal. 0 keeps the message visible.'));
        $fields->replaceField(F::AUTO_HIDE_SECONDS, $autoHideSecondsField);
        $fields->dataFieldByName(F::IP_RANGES)?->setDescription(_t(__CLASS__ . '.IP_HELP',
            'Leave empty for everyone. Separate IPv4/IPv6 addresses, CIDR networks or start-end ranges with commas or new lines.'));
        $fields->dataFieldByName(F::START_DATE)?->setDescription(_t(__CLASS__ . '.START_DATE_HELP',
            'Optional. Leave empty to display immediately when active.'));
        $fields->dataFieldByName(F::END_DATE)?->setDescription(_t(__CLASS__ . '.END_DATE_HELP',
            'Optional. The message is hidden from this time onward. Leave empty for no end date.'));
    }

    /** Only administrators may create frontend notices. */
    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    /** Only administrators may edit frontend notices. */
    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    /** Only administrators may delete frontend notices. */
    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    /** Only administrators may inspect records in the CMS; middleware handles public delivery. */
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }
}

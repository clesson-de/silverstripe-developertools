<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Models;

use Clesson\Silverstripe\DeveloperTools\Constants\MonitoringField as F;
use Clesson\Silverstripe\DeveloperTools\Constants\MonitoringStatus;
use Clesson\Silverstripe\DeveloperTools\Forms\GridFieldConfig_MonitoringChecks;
use Clesson\Silverstripe\DeveloperTools\Forms\GridFieldConfig_MonitoringSnapshots;
use Clesson\Silverstripe\DeveloperTools\Helpers\MonitoringURL;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringHttpClient;
use DomainException;
use LeKoala\Encrypt\EncryptedDBText;
use LeKoala\Encrypt\HasEncryptedFields;
use RuntimeException;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\PasswordField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\Security\Permission;

/**
 * An administrator-managed customer endpoint; credentials are write-only in CMS.
 *
 * @property string $Name
 * @property string $BaseURL
 * @property bool $Active
 * @property string $ApiToken
 * @property string|null $FirstCheckedAt
 * @property string|null $LastCheckedAt
 * @property string|null $LastSuccessfulCheckAt
 * @property string $LastStatus
 * @property string $LastError
 * @property int $ConfigID
 * @property int $LatestSnapshotID
 * @property-write string $ReplacementToken
 * @property-read string|null $LastChangeAt
 * @method DeveloperToolsConfig Config()
 * @method MonitoringSnapshot LatestSnapshot()
 * @method HasManyList|MonitoringSnapshot[] Snapshots()
 * @method HasManyList|MonitoringCheck[] Checks()
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Models
 */
class MonitoredWebsite extends DataObject
{
    use HasEncryptedFields;

    /** @config */
    private static string $table_name = 'DeveloperTools_MonitoredWebsite';
    /** @config */
    private static string $default_sort = F::NAME . ' ASC';
    /** @config */
    private static string $general_search_field = F::NAME;
    /** @config */
    private static array $db = [
        F::NAME => 'Varchar(100)',
        F::BASE_URL => 'Varchar(512)',
        F::ACTIVE => 'Boolean',
        F::API_TOKEN => EncryptedDBText::class,
        F::FIRST_CHECKED_AT => 'Datetime',
        F::LAST_CHECKED_AT => 'Datetime',
        F::LAST_SUCCESSFUL_CHECK_AT => 'Datetime',
        F::LAST_STATUS => 'Varchar(12)',
        F::LAST_ERROR => 'Text',
    ];
    /** @config */
    private static array $has_one = [
        F::CONFIG => DeveloperToolsConfig::class,
        F::LATEST_SNAPSHOT => MonitoringSnapshot::class,
    ];
    /** @config */
    private static array $has_many = [
        F::SNAPSHOTS => MonitoringSnapshot::class . '.' . F::WEBSITE,
        F::CHECKS => MonitoringCheck::class . '.' . F::WEBSITE,
    ];
    /** @config */
    private static array $indexes = [
        'BaseURLUnique' => ['type' => 'unique', 'columns' => [F::BASE_URL]],
    ];
    /** @config */
    private static array $summary_fields = [F::NAME, F::BASE_URL, F::ACTIVE, F::LAST_CHECKED_AT];
    /** @config */
    private static array $searchable_fields = [F::NAME, F::BASE_URL, F::ACTIVE];

    /** Uses the encryption package's transparent decryption integration. */
    public function getField(string $field): mixed
    {
        if ($field === F::API_TOKEN && !empty($this->record[$field]) && !Environment::getEnv('ENCRYPTION_KEY')) {
            throw new RuntimeException('Monitoring requires a configured ENCRYPTION_KEY.');
        }
        return $this->getEncryptedField($field);
    }

    /** Supports both FormField::saveInto (setField) and the ordinary custom setter. */
    public function setField(string $fieldName, mixed $value): static
    {
        if ($fieldName === F::REPLACEMENT_TOKEN) {
            return $this->setReplacementToken((string) $value);
        }
        return $this->setEncryptedField($fieldName, $value);
    }

    /** Blank edits retain the credential; no plaintext replacement is held separately. */
    public function setReplacementToken(string $value): static
    {
        if ($value !== '') {
            $this->setEncryptedField(F::API_TOKEN, $value);
        }
        return $this;
    }

    /** System-managed attempt fields start empty; new sites are enabled. */
    public function populateDefaults(): void
    {
        parent::populateDefaults();
        $this->Active = true;
    }

    /** Validates required input, canonical uniqueness and header-safe credentials. */
    public function validate(): ValidationResult
    {
        $result = parent::validate();
        $this->validateRequiredFields($result);
        $this->validateURL($result);
        if ($this->ApiToken && !MonitoringHttpClient::validToken((string) $this->ApiToken)) {
            $result->addError(_t(__CLASS__ . '.INVALID_TOKEN', 'The API token must contain only visible ASCII characters (maximum 4096).'));
        }
        return $result;
    }

    /** Uses shared required-field validation messages for user input. */
    private function validateRequiredFields(ValidationResult $result): void
    {
        foreach ([F::NAME, F::BASE_URL, F::API_TOKEN] as $field) {
            if (trim((string) $this->getField($field)) === '') {
                $result->addError(_t(Form::class . '.FIELDISREQUIRED', '{name} is required', [
                    'name' => $this->fieldLabel($field),
                ]));
            }
        }
    }

    /** Canonicalizes before the database unique index is applied. */
    private function validateURL(ValidationResult $result): void
    {
        try {
            $this->BaseURL = MonitoringURL::singleton()->normalize((string) $this->BaseURL);
        } catch (DomainException $exception) {
            $result->addError(_t(__CLASS__ . '.INVALID_URL', 'Enter a public HTTPS base URL without credentials, query or fragment.'));
            return;
        }
        if (static::get()->filter(F::BASE_URL, $this->BaseURL)->exclude('ID', $this->ID)->exists()) {
            $result->addError(_t(__CLASS__ . '.DUPLICATE_URL', 'This base URL is already monitored.'));
        }
    }

    /** Refuses implicit key generation; production credentials require a server-held key. */
    protected function onBeforeWrite(): void
    {
        if (!Environment::getEnv('ENCRYPTION_KEY')) {
            throw new RuntimeException('Monitoring requires a configured ENCRYPTION_KEY.');
        }
        $this->BaseURL = MonitoringURL::singleton()->normalize((string) $this->BaseURL);
        parent::onBeforeWrite();
    }

    /** Returns the detection time, not a claimed deployment time. */
    public function getLastChangeAt(): ?string
    {
        return $this->LatestSnapshotID ? $this->LatestSnapshot()->ObservedAt : null;
    }

    /** Includes all stored fields, relations and computed list columns. */
    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), F::options(), [
            'ID' => _t('Clesson\Silverstripe\DeveloperTools\Common.ID', 'ID'),
            'Created' => _t('Clesson\Silverstripe\DeveloperTools\Common.CREATED', 'Created'),
            'LastEdited' => _t('Clesson\Silverstripe\DeveloperTools\Common.LAST_EDITED', 'Last edited'),
        ]);
    }

    /** Builds configuration with a genuinely empty credential input and readonly system fields. */
    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName([F::API_TOKEN, F::CONFIG . 'ID', F::LATEST_SNAPSHOT . 'ID', F::SNAPSHOTS, F::CHECKS]);
        /** @var PasswordField $replacementTokenField */
        $replacementTokenField = PasswordField::create(F::REPLACEMENT_TOKEN, F::label(F::REPLACEMENT_TOKEN), '');
        $replacementTokenField->setAttribute('autocomplete', 'new-password');
        $fields->addFieldToTab('Root.Main', $replacementTokenField);
        $this->readonlySystemFields($fields);
        if ($this->isInDB()) {
            $this->addHistoryFields($fields);
        }
        return $fields;
    }

    /** Prevents editing scheduler-owned state through the CMS. */
    private function readonlySystemFields(FieldList $fields): void
    {
        foreach ([F::FIRST_CHECKED_AT, F::LAST_CHECKED_AT, F::LAST_SUCCESSFUL_CHECK_AT, F::LAST_STATUS, F::LAST_ERROR] as $name) {
            $field = $fields->dataFieldByName($name);
            if ($field) {
                $fields->replaceField($name, $field->performReadonlyTransformation());
            }
        }
        $fields->dataFieldByName(F::LAST_STATUS)?->setValue(MonitoringStatus::label($this->LastStatus));
        $fields->dataFieldByName(F::LAST_ERROR)?->setValue(MonitoringCheck::errorLabel((string) $this->LastError));
    }

    /** Uses the parent's dedicated readonly history configurations. */
    private function addHistoryFields(FieldList $fields): void
    {
        /** @var GridField $snapshotsField */
        $snapshotsField = GridField::create(F::SNAPSHOTS, F::label(F::SNAPSHOTS), $this->Snapshots(), GridFieldConfig_MonitoringSnapshots::create());
        /** @var GridField $checksField */
        $checksField = GridField::create(F::CHECKS, F::label(F::CHECKS), $this->Checks(), GridFieldConfig_MonitoringChecks::create());
        $fields->addFieldToTab('Root.' . F::SNAPSHOTS, $snapshotsField);
        $fields->addFieldToTab('Root.' . F::CHECKS, $checksField);
    }

    /** Only administrators may register customer credentials. */
    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    /** Only administrators may change customer configuration. */
    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    /** Deactivate websites instead of deleting their history. */
    public function canDelete($member = null): bool
    {
        return false;
    }

    /** Only administrators may inspect customer inventory. */
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }
}

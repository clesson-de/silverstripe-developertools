<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Models;

use Clesson\Silverstripe\DeveloperTools\Constants\MonitoringField as F;
use Clesson\Silverstripe\DeveloperTools\Constants\ProjectInformationField;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringInventory;
use DomainException;
use LogicException;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

/**
 * Immutable validated inventory captured when a change is observed.
 *
 * @property string $ObservedAt
 * @property string $Payload
 * @property string $ContentHash
 * @property int $WebsiteID
 * @property-read array $Inventory
 * @method MonitoredWebsite Website()
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Models
 */
class MonitoringSnapshot extends DataObject
{
    /** @config */
    private static string $table_name = 'DeveloperTools_MonitoringSnapshot';
    /** @config */
    private static string $default_sort = F::OBSERVED_AT . ' DESC, ID DESC';
    /** @config */
    private static string $general_search_field = F::CONTENT_HASH;
    /** @config */
    private static array $db = [
        F::OBSERVED_AT => 'Datetime',
        F::PAYLOAD => 'Text',
        F::CONTENT_HASH => 'Varchar(64)',
    ];
    /** @config */
    private static array $has_one = [F::WEBSITE => MonitoredWebsite::class];
    /** @config */
    private static array $summary_fields = [F::OBSERVED_AT, F::CONTENT_HASH];

    /** Preserves framework defaults; timestamps are supplied by the checking service. */
    public function populateDefaults(): void
    {
        parent::populateDefaults();
    }

    /** Validates the stored contract without reflecting remote values in errors. */
    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->WebsiteID || !$this->ObservedAt) {
            $result->addError(_t(__CLASS__ . '.REQUIRED_CONTEXT', 'A website and observation time are required.'));
        }
        try {
            $this->getInventory();
        } catch (DomainException $exception) {
            $result->addError(_t(__CLASS__ . '.INVALID_PAYLOAD', 'The inventory payload is invalid.'));
        }
        return $result;
    }

    /** Canonicalizes once; existing persisted snapshots cannot be rewritten. */
    protected function onBeforeWrite(): void
    {
        if ($this->isInDB()) {
            throw new LogicException('Monitoring snapshots are immutable.');
        }
        $this->Payload = MonitoringInventory::singleton()->encode($this->getInventory());
        $this->ContentHash = hash('sha256', $this->Payload);
        parent::onBeforeWrite();
    }

    /** Retention deletion is deliberately unsupported. */
    protected function onBeforeDelete(): void
    {
        throw new LogicException('Monitoring snapshots cannot be deleted.');
    }

    /** Returns only the validated, canonical inventory contract. */
    public function getInventory(): array
    {
        return MonitoringInventory::singleton()->normalize((string) $this->Payload);
    }

    /** Includes every persisted field and relation. */
    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), F::options(), [
            'ID' => _t('Clesson\Silverstripe\DeveloperTools\Common.ID', 'ID'),
            'Created' => _t('Clesson\Silverstripe\DeveloperTools\Common.CREATED', 'Created'),
            'LastEdited' => _t('Clesson\Silverstripe\DeveloperTools\Common.LAST_EDITED', 'Last edited'),
        ]);
    }

    /** Displays a plain escaped inventory table and readonly JSON, never external metadata. */
    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields()->makeReadonly();
        /** @var LiteralField $inventoryField */
        $inventoryField = LiteralField::create(F::INVENTORY, $this->inventoryTable());
        $fields->addFieldToTab('Root.Main', $inventoryField);
        return $fields;
    }

    /** Renders historical values without latest-release requests or nested GridFields. */
    private function inventoryTable(): string
    {
        $inventory = $this->getInventory();
        $rows = '';
        foreach (MonitoringInventory::VERSIONS as $key) {
            $rows .= $this->inventoryRow(ProjectInformationField::label($key), $inventory[$key]);
        }
        $rows .= $this->inventoryRow(_t(__CLASS__ . '.DATABASE_PRODUCT', 'Database product'), $inventory[MonitoringInventory::DATABASE_PRODUCT]);
        foreach ($inventory[MonitoringInventory::PACKAGES] as $package) {
            $rows .= $this->inventoryRow($package[MonitoringInventory::PACKAGE_NAME], $package[MonitoringInventory::PACKAGE_VERSION]);
        }
        $caption = _t(__CLASS__ . '.DETECTION_NOTICE', 'Observed at is the time a change was detected, not the deployment time.');
        return '<table class="ss-gridfield-table"><caption>' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8')
            . '</caption><tbody>' . $rows . '</tbody></table>';
    }

    /** Escapes both columns even though inventory validation is already strict. */
    private function inventoryRow(string $label, string $value): string
    {
        return '<tr><th scope="row">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</th><td>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }

    /** History is created only by the checking service, never CMS users. */
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    /** Persisted history is immutable. */
    public function canEdit($member = null): bool
    {
        return false;
    }

    /** No history retention deletion is implemented. */
    public function canDelete($member = null): bool
    {
        return false;
    }

    /** Only administrators may inspect historical inventories. */
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }
}

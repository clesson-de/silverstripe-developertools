<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Models;

use Clesson\Silverstripe\DeveloperTools\Constants\MonitoringField as F;
use Clesson\Silverstripe\DeveloperTools\Constants\MonitoringStatus;
use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReport;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReportRenderer;
use DomainException;
use JsonException;
use LogicException;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

/**
 * Immutable outcome of a completed attempt, with only whitelisted error text.
 *
 * @property string $StartedAt
 * @property string $FinishedAt
 * @property string $Status
 * @property string $ErrorMessage
 * @property string $HealthResults
 * @property-read array $HealthChecks
 * @property-read string $HealthStatus
 * @property int $WebsiteID
 * @property int $SnapshotID
 * @method MonitoredWebsite Website()
 * @method MonitoringSnapshot Snapshot()
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Models
 */
class MonitoringCheck extends DataObject
{
    /** Safe errors: no URL, credential, response body or exception text. */
    public const FETCH_FAILED = 'The website request failed or its destination was rejected.';
    /** Fixed message for rejected inventories. */
    public const INVALID_INVENTORY = 'The website returned an invalid inventory.';

    /** Translates whitelisted stored error codes without exposing remote exception text. */
    public static function errorLabel(string $error): string
    {
        return match ($error) {
            self::FETCH_FAILED => _t(__CLASS__ . '.FETCH_FAILED', self::FETCH_FAILED),
            self::INVALID_INVENTORY => _t(__CLASS__ . '.INVALID_INVENTORY', self::INVALID_INVENTORY),
            default => $error,
        };
    }

    /** @config */
    private static string $table_name = 'DeveloperTools_MonitoringCheck';
    /** @config */
    private static string $default_sort = F::STARTED_AT . ' DESC, ID DESC';
    /** @config */
    private static string $general_search_field = F::STATUS;
    /** @config */
    private static array $db = [
        F::STARTED_AT => 'Datetime',
        F::FINISHED_AT => 'Datetime',
        F::STATUS => 'Varchar(12)',
        F::ERROR_MESSAGE => 'Text',
        F::HEALTH_RESULTS => 'Text',
    ];
    /** @config */
    private static array $has_one = [
        F::WEBSITE => MonitoredWebsite::class,
        F::SNAPSHOT => MonitoringSnapshot::class,
    ];
    /** @config */
    private static array $summary_fields = [F::STARTED_AT, F::FINISHED_AT, F::STATUS, F::HEALTH_STATUS, F::ERROR_MESSAGE];

    /** Keeps framework defaults; the service supplies all outcome fields. */
    public function populateDefaults(): void
    {
        parent::populateDefaults();
    }

    /** Ensures persisted attempts have a complete, safe outcome. */
    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->WebsiteID || !$this->StartedAt || !$this->FinishedAt
            || !isset(MonitoringStatus::options()[$this->Status])
            || !in_array((string) $this->ErrorMessage, ['', self::FETCH_FAILED, self::INVALID_INVENTORY], true)
            || ($this->Status === MonitoringStatus::FAILED) !== ((string) $this->ErrorMessage !== '')
            || ($this->Status !== MonitoringStatus::FAILED && !$this->SnapshotID)
        ) {
            $result->addError(_t(__CLASS__ . '.INVALID_OUTCOME', 'The monitoring attempt is incomplete or invalid.'));
        }
        return $result;
    }

    /** Forbids rewriting any persisted outcome, including website/snapshot links. */
    protected function onBeforeWrite(): void
    {
        if ($this->isInDB()) {
            throw new LogicException('Monitoring checks are immutable.');
        }
        $this->getHealthChecks();
        parent::onBeforeWrite();
    }

    /** Reads bounded validated health outcomes; legacy or failed attempts have none. */
    public function getHealthChecks(): array
    {
        if (!$this->HealthResults) {
            return [];
        }
        try {
            $results = json_decode($this->HealthResults, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DomainException('Invalid saved health report.');
        }
        return HealthReport::singleton()->normalize($results);
    }

    /** Errors and warnings are never diluted by successful checks or version percentages. */
    public function getHealthStatus(): string
    {
        $statuses = array_column($this->getHealthChecks(), 'status');
        foreach ([HealthStatus::ERROR, HealthStatus::WARNING, HealthStatus::UNKNOWN] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }
        return $statuses ? HealthStatus::OK : HealthStatus::UNKNOWN;
    }

    /** Retention deletion is deliberately unsupported. */
    protected function onBeforeDelete(): void
    {
        throw new LogicException('Monitoring checks cannot be deleted.');
    }

    /** Includes all fields and relations. */
    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), F::options(), [
            'ID' => _t('Clesson\Silverstripe\DeveloperTools\Common.ID', 'ID'),
            'Created' => _t('Clesson\Silverstripe\DeveloperTools\Common.CREATED', 'Created'),
            'LastEdited' => _t('Clesson\Silverstripe\DeveloperTools\Common.LAST_EDITED', 'Last edited'),
        ]);
    }

    /** History details have no editable fields. */
    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields()->makeReadonly();
        $fields->dataFieldByName(F::STATUS)?->setValue(MonitoringStatus::label($this->Status));
        $fields->dataFieldByName(F::ERROR_MESSAGE)?->setValue(self::errorLabel((string) $this->ErrorMessage));
        $fields->addFieldToTab('Root.Main', LiteralField::create('HealthChecks',
            HealthReportRenderer::create()->render($this->getHealthChecks())));
        return $fields;
    }

    /** Only the service creates attempts, not CMS users. */
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    /** History is immutable even for administrators. */
    public function canEdit($member = null): bool
    {
        return false;
    }

    /** No deletion or automatic retention policy is implemented. */
    public function canDelete($member = null): bool
    {
        return false;
    }

    /** Only administrators may inspect attempt outcomes. */
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }
}

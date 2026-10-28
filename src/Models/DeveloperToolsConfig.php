<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Models;

use Clesson\Silverstripe\Dashboard\Forms\DashboardField;
use Clesson\Silverstripe\DeveloperTools\Forms\AnalysisTabSet;
use Clesson\Silverstripe\DeveloperTools\Forms\GridFieldConfig_WebsitesInConfiguration;
use Clesson\Silverstripe\DeveloperTools\Forms\GridFieldConfig_WebsitesInInfo;
use Clesson\Silverstripe\DeveloperTools\Panels\ProjectInformationPanel;
use Clesson\Silverstripe\DeveloperTools\Panels\MonitoringWebsitesPanel;
use Clesson\Silverstripe\DeveloperTools\Helpers\MonitoringRecipients;
use DomainException;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\HasManyList;
use SilverStripe\Security\Permission;
use SilverStripe\View\Requirements;

/**
 * Singleton containing the developer tools dashboard, monitoring and analysis.
 *
 * @property string $Title
 * @property string $NotificationEmails
 * @method HasManyList|MonitoredWebsite[] Websites()
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Models
 */
class DeveloperToolsConfig extends DataObject
{
    /** Internal persistence field; not an editable setting. */
    public const TITLE_FIELD = 'Title';
    /** Comma-separated summary recipients. */
    public const NOTIFICATION_EMAILS = 'NotificationEmails';

    /** @config */
    private static string $table_name = 'DeveloperTools_Config';

    /** @config */
    private static string $default_sort = 'Created ASC';

    /** @config */
    private static string $general_search_field = self::TITLE_FIELD;

    /** @config */
    private static array $db = [
        self::TITLE_FIELD => 'Varchar(100)',
        self::NOTIFICATION_EMAILS => 'Text',
    ];

    /** @config */
    private static array $has_many = [
        'Websites' => MonitoredWebsite::class . '.Config',
    ];

    /**
     * Returns or creates the shared configuration record.
     */
    public static function currentRecord(): static
    {
        $record = static::get()->setUseCache(true)->first();
        if (!$record) {
            $record = static::create();
            $record->write();
        }
        return $record;
    }

    /** @inheritdoc */
    public function populateDefaults(): void
    {
        parent::populateDefaults();
        $this->Title = 'Developer Tools';
    }

    /**
     * Creates the singleton during database builds.
     */
    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();
        if (!static::get()->exists()) {
            static::currentRecord();
            DB::alteration_message('Created default DeveloperToolsConfig record', 'created');
        }
    }

    /** @inheritdoc */
    public function validate(): ValidationResult
    {
        $result = parent::validate();
        try {
            MonitoringRecipients::parse((string) $this->NotificationEmails);
        } catch (DomainException $exception) {
            $result->addFieldError(self::NOTIFICATION_EMAILS,
                _t(__CLASS__ . '.INVALID_EMAILS', 'Enter at most 50 valid email addresses separated by commas.'));
        }
        return $result;
    }

    /** @inheritdoc */
    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), [
            self::TITLE_FIELD => _t(__CLASS__ . '.TITLE', 'Developer Tools'),
            self::NOTIFICATION_EMAILS => _t(__CLASS__ . '.NOTIFICATION_EMAILS', 'Email recipients'),
            'Websites' => _t(__CLASS__ . '.WEBSITES', 'Websites'),
            'ID' => _t('Clesson\Silverstripe\DeveloperTools\Common.ID', 'ID'),
            'Created' => _t('Clesson\Silverstripe\DeveloperTools\Common.CREATED', 'Created'),
            'LastEdited' => _t('Clesson\Silverstripe\DeveloperTools\Common.LAST_EDITED', 'Last edited'),
        ]);
    }

    /**
     * Places Dashboard first, Monitoring second and Analysis last.
     */
    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName('Root');
        /** @var TabSet $rootField */
        $rootField = TabSet::create(
            'Root',
            $this->buildDashboardTab(),
            $this->buildMonitoringTabSet(),
            (new AnalysisTabSet())->build()
        );
        $fields->push($rootField);
        return $fields;
    }

    /**
     * Creates the dashboard using the shared module dashboard system.
     */
    private function buildDashboardTab(): Tab
    {
        /** @var DashboardField $dashboardField */
        $dashboardField = DashboardField::create('DeveloperToolsDashboard', 'developertools');
        $dashboardField->setDefaultPanels([ProjectInformationPanel::class, MonitoringWebsitesPanel::class]);
        $dashboardField->setAllowedPanels([ProjectInformationPanel::class, MonitoringWebsitesPanel::class]);
        /** @var Tab $dashboardTab */
        $dashboardTab = Tab::create('Main', _t(__CLASS__ . '.TAB_DASHBOARD', 'Dashboard'), $dashboardField);
        return $dashboardTab;
    }

    /**
     * Provides the monitoring overview and website configuration.
     */
    private function buildMonitoringTabSet(): TabSet
    {
        Requirements::javascript('clesson-de/silverstripe-developertools:client/admin/dist/bundle.js');
        Requirements::css('clesson-de/silverstripe-developertools:client/admin/dist/bundle.css');
        /** @var TabSet $monitoringTabSet */
        $monitoringTabSet = TabSet::create(
            'Monitoring',
            _t(__CLASS__ . '.TAB_MONITORING', 'Monitoring'),
            $this->buildMonitoringTab('Info', _t(__CLASS__ . '.TAB_INFO', 'Info'), false),
            $this->buildMonitoringTab('Configuration', _t(__CLASS__ . '.TAB_CONFIGURATION', 'Configuration'), true),
            $this->buildNotificationsTab()
        );
        return $monitoringTabSet;
    }

    /** Empty recipients disable automatic run summaries without affecting manual checks. */
    private function buildNotificationsTab(): Tab
    {
        $field = TextareaField::create(self::NOTIFICATION_EMAILS, $this->fieldLabel(self::NOTIFICATION_EMAILS));
        $field->setRows(3)->setDescription(_t(__CLASS__ . '.NOTIFICATION_HELP',
            'Comma-separated addresses. One summary per automatic run; no emails for manual checks. Leave empty to disable.'));
        return Tab::create('Notifications', _t(__CLASS__ . '.TAB_NOTIFICATIONS', 'Notifications'), $field);
    }

    /**
     * Uses dedicated configurations for editable sites and the read-only overview.
     */
    private function buildMonitoringTab(string $name, string $title, bool $configuration): Tab
    {
        /** @var GridField $websitesField */
        $websitesField = GridField::create(
            $configuration ? 'Websites' : 'MonitoringWebsites',
            '',
            $this->Websites(),
            $configuration ? GridFieldConfig_WebsitesInConfiguration::create() : GridFieldConfig_WebsitesInInfo::create()
        );
        if (!$configuration) {
            $websitesField->addExtraClass('developer-tools-monitoring');
            $websitesField->setAttribute('data-loading', _t(__CLASS__ . '.LOADING', 'Checking / loading information...'));
            $websitesField->setAttribute('data-failure', _t(__CLASS__ . '.REQUEST_FAILED', 'The request failed. Please retry.'));
            $websitesField->setAttribute('data-csrf-error', _t(__CLASS__ . '.CSRF_ERROR', 'The form security token is missing. Reload the page.'));
        }
        /** @var Tab $monitoringTab */
        $monitoringTab = Tab::create($name, $title, $websitesField);
        return $monitoringTab;
    }

    /**
     * Only administrators may create the singleton.
     */
    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    /**
     * Only administrators may edit the singleton.
     */
    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    /**
     * The shared configuration must not be deleted.
     */
    public function canDelete($member = null): bool
    {
        return false;
    }

    /**
     * Only administrators may view analysis and monitoring information.
     */
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }
}

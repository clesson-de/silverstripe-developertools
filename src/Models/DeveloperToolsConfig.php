<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Models;

use Clesson\Silverstripe\Dashboard\Forms\DashboardField;
use Clesson\Silverstripe\Hints\Forms\HintField;
use Clesson\Silverstripe\DeveloperTools\Forms\AnalysisTabSet;
use Clesson\Silverstripe\DeveloperTools\Forms\GridFieldConfig_WebsitesInConfiguration;
use Clesson\Silverstripe\DeveloperTools\Forms\GridFieldConfig_WebsitesInInfo;
use Clesson\Silverstripe\DeveloperTools\Forms\GridFieldConfig_ToastMessages;
use Clesson\Silverstripe\DeveloperTools\Constants\ToastEnvironment;
use Clesson\Silverstripe\DeveloperTools\Constants\ToastField;
use Clesson\Silverstripe\DeveloperTools\Constants\ToastHorizontalPosition;
use Clesson\Silverstripe\DeveloperTools\Constants\OfflineField;
use Clesson\Silverstripe\DeveloperTools\Constants\ToastType;
use Clesson\Silverstripe\DeveloperTools\Constants\ToastVerticalPosition;
use Clesson\Silverstripe\DeveloperTools\Forms\OfflineModeTab;
use Clesson\Silverstripe\DeveloperTools\Services\OfflineMode;
use Clesson\Silverstripe\DeveloperTools\Panels\ProjectInformationPanel;
use Clesson\Silverstripe\DeveloperTools\Panels\MonitoringWebsitesPanel;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\ListboxField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\View\Requirements;

/**
 * Singleton containing the developer tools dashboard, monitoring, overlays and offline settings.
 *
 * @property string $Title
 * @property bool $OfflineActive
 * @property string|null $OfflineStart
 * @property string|null $OfflineEnd
 * @property string $OfflineIPExceptions
 * @method HasManyList|MonitoredWebsite[] Websites()
 * @method HasManyList|ToastMessage[] ToastMessages()
 * @method ManyManyList|Member[] NotifyMembers()
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Models
 */
class DeveloperToolsConfig extends DataObject
{
    /** Internal persistence field; not an editable setting. */
    public const TITLE_FIELD = 'Title';
    /** Members receiving automatic monitoring summaries. */
    public const NOTIFY_MEMBERS = 'NotifyMembers';
    /** Default warning shown in non-live environments after installation. */
    private const DEFAULT_REVIEW_TOAST_TITLE = 'Test environment';
    /** English default message to avoid confusion on non-live systems. */
    private const DEFAULT_REVIEW_TOAST_MESSAGE = 'This is a test environment. Changes here are not for production approval.';

    /** @config */
    private static string $table_name = 'DeveloperTools_Config';

    /** @config */
    private static string $default_sort = 'Created ASC';

    /** @config */
    private static string $general_search_field = self::TITLE_FIELD;

    /** @config */
    private static array $db = [
        self::TITLE_FIELD => 'Varchar(100)',
        OfflineField::ACTIVE => 'Boolean',
        OfflineField::START => 'Datetime',
        OfflineField::END => 'Datetime',
        OfflineField::IP_EXCEPTIONS => 'Text',
    ];

    /** @config */
    private static array $has_many = [
        'Websites' => MonitoredWebsite::class . '.Config',
        ToastField::TOAST_MESSAGES => ToastMessage::class . '.' . ToastField::SETTINGS,
    ];

    /** @config */
    private static array $many_many = [
        self::NOTIFY_MEMBERS => Member::class,
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
        $recordExisted = static::get()->exists();
        $record = static::currentRecord();
        if (!$recordExisted) {
            DB::alteration_message('Created default DeveloperToolsConfig record', 'created');
        }
        $this->ensureDefaultReviewToast($record);
    }

    /** Creates one default overlay for dev/review when no custom overlay exists yet. */
    private function ensureDefaultReviewToast(self $record): void
    {
        if ($record->ToastMessages()->exists()) {
            return;
        }
        ToastMessage::create([
            ToastField::SETTINGS . 'ID' => $record->ID,
            ToastField::TITLE => self::DEFAULT_REVIEW_TOAST_TITLE,
            ToastField::MESSAGE => self::DEFAULT_REVIEW_TOAST_MESSAGE,
            ToastField::TYPE => ToastType::ALERT,
            ToastField::ENVIRONMENT => ToastEnvironment::DEV,
            ToastField::AUTO_HIDE_SECONDS => 0,
            ToastField::REMOVABLE => true,
            ToastField::HORIZONTAL_POSITION => ToastHorizontalPosition::RIGHT,
            ToastField::VERTICAL_POSITION => ToastVerticalPosition::BOTTOM,
            ToastField::ACTIVE => true,
        ])->write();
        DB::alteration_message('Created default test-environment overlay for dev/review', 'created');
    }

    /** @inheritdoc */
    public function validate(): ValidationResult
    {
        $result = parent::validate();
        OfflineMode::create()->validate($this, $result);
        return $result;
    }

    /** @inheritdoc */
    public function fieldLabels($includerelations = true): array
    {
        return array_merge(parent::fieldLabels($includerelations), OfflineField::options(), [
            self::TITLE_FIELD => _t(__CLASS__ . '.TITLE', 'Developer Tools'),
            self::NOTIFY_MEMBERS => _t(__CLASS__ . '.NOTIFY_MEMBERS', 'Notification recipients'),
            'Websites' => _t(__CLASS__ . '.WEBSITES', 'Websites'),
            ToastField::TOAST_MESSAGES => ToastField::label(ToastField::TOAST_MESSAGES),
            'ID' => _t('Clesson\Silverstripe\DeveloperTools\Common.ID', 'ID'),
            'Created' => _t('Clesson\Silverstripe\DeveloperTools\Common.CREATED', 'Created'),
            'LastEdited' => _t('Clesson\Silverstripe\DeveloperTools\Common.LAST_EDITED', 'Last edited'),
        ]);
    }

    /**
     * Places Dashboard first, followed by Monitoring, Overlays, Offline mode and Analysis.
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
            $this->buildToastsTab(),
            (new OfflineModeTab())->build(),
            (new AnalysisTabSet())->build()
        );
        $fields->push($rootField);
        return $fields;
    }

    /** Edits frontend notices without exposing a separate admin route. */
    private function buildToastsTab(): Tab
    {
        /** @var HintField $toastHintField */
        $toastHintField = HintField::create(
            'ToastHint',
            _t(__CLASS__ . '.TOAST_HINT_TITLE', 'Website overlays'),
            _t(__CLASS__ . '.TOAST_HINT', 'Overlays display information, warnings or alerts above the website content, for example maintenance notices or important announcements. Visibility can be restricted by environment, IP addresses, user groups (including subgroups) and an optional start/end date and time. All configured filters apply together. Position, automatic dismissal and the close button can be configured individually.')
        );
        /** @var GridField $toastMessagesField */
        $toastMessagesField = GridField::create(
            ToastField::TOAST_MESSAGES,
            '',
            $this->ToastMessages(),
            GridFieldConfig_ToastMessages::create()
        );
        /** @var Tab $toastsTab */
        $toastsTab = Tab::create('Toasts', ToastField::label(ToastField::TOAST_MESSAGES), $toastHintField, $toastMessagesField);
        return $toastsTab;
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
    private function buildMonitoringTabSet(): Tab
    {
        Requirements::javascript('clesson-de/silverstripe-developertools:client/admin/dist/bundle.js');
        Requirements::css('clesson-de/silverstripe-developertools:client/admin/dist/bundle.css');
        /** @var TabSet $monitoringTabSet */
        $monitoringTabSet = TabSet::create(
            'MonitoringTabs',
            $this->buildMonitoringTab('Info', _t(__CLASS__ . '.TAB_INFO', 'Info'), false),
            $this->buildMonitoringTab('Configuration', _t(__CLASS__ . '.TAB_CONFIGURATION', 'Configuration'), true),
            $this->buildNotificationsTab()
        );
        /** @var Tab $monitoringTab */
        $monitoringTab = Tab::create(
            'Monitoring',
            _t(__CLASS__ . '.TAB_MONITORING', 'Monitoring'),
            $this->buildMonitoringHint(),
            $monitoringTabSet
        );
        return $monitoringTab;
    }

    /** Empty recipients disable automatic run summaries without affecting manual checks. */
    private function buildNotificationsTab(): Tab
    {
        /** @var ListboxField $notifyMembersField */
        $notifyMembersField = ListboxField::create(
            self::NOTIFY_MEMBERS,
            $this->fieldLabel(self::NOTIFY_MEMBERS),
            Member::get()->map('ID', 'Name')->toArray()
        );
        $notifyMembersField->setDescription(_t(__CLASS__ . '.NOTIFICATION_HELP',
            'Select members to receive one summary per automatic run at their current email address. No emails for manual checks. Leave empty to disable. Selection does not grant CMS permissions.'));
        return Tab::create('Notifications', _t(__CLASS__ . '.TAB_NOTIFICATIONS', 'Notifications'), $notifyMembersField);
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

    /** Explains the monitoring workflow above the shared monitoring tabset. */
    private function buildMonitoringHint(): HintField
    {
        return HintField::create(
            'MonitoringHint',
            _t(__CLASS__ . '.MONITORING_HINT_TITLE', 'Website monitoring'),
            _t(__CLASS__ . '.MONITORING_HINT', 'Monitoring provides an overview of your websites, their availability, software versions and health checks. It also checks whether HTTP redirects directly to HTTPS; unreachable HTTP endpoints are marked as not assessable. Manage monitored websites under Configuration, review results under Info and configure email summaries under Notifications.')
        );
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

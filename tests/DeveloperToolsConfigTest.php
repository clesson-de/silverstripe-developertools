<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tests;

use Clesson\Silverstripe\Dashboard\Forms\DashboardField;
use Clesson\Silverstripe\Hints\Forms\HintField;
use Clesson\Silverstripe\DeveloperTools\Admins\DeveloperToolsAdmin;
use Clesson\Silverstripe\DeveloperTools\Constants\ToastType;
use Clesson\Silverstripe\DeveloperTools\Models\DeveloperToolsConfig;
use Clesson\Silverstripe\DeveloperTools\Models\ToastMessage;
use Clesson\Silverstripe\DeveloperTools\Panels\ProjectInformationPanel;
use Clesson\Silverstripe\DeveloperTools\Panels\MonitoringWebsitesPanel;
use Clesson\Silverstripe\DeveloperTools\Services\ProjectInformationService;
use Clesson\Silverstripe\DeveloperTools\Services\HealthCheckService;
use SilverStripe\Admin\SingleRecordAdmin;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Forms\ListboxField;

/**
 * Covers singleton persistence, CMS tab structure and dashboard authorization.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class DeveloperToolsConfigTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected $autoFollowRedirection = false;

    /**
     * Creates one persistent default record and uses the singleton editor.
     */
    public function testSingletonAndPermissions(): void
    {
        $config = DeveloperToolsConfig::currentRecord();
        self::assertGreaterThan(0, $config->ID);
        self::assertSame($config->ID, DeveloperToolsConfig::currentRecord()->ID);
        $config->requireDefaultRecords();
        self::assertSame(1, DeveloperToolsConfig::get()->count());
        self::assertSame(1, $config->ToastMessages()->count());
        $defaultToast = $config->ToastMessages()->first();
        self::assertSame('Test environment', $defaultToast->Title);
        self::assertSame('This is a test environment. Changes here are not for production approval.',
            $defaultToast->dbObject('Message')->getPlain());
        self::assertSame('dev', $defaultToast->Environment);
        self::assertSame(ToastType::ALERT, $defaultToast->Type);
        self::assertSame('right', $defaultToast->HorizontalPosition);
        self::assertSame('bottom', $defaultToast->VerticalPosition);
        self::assertSame(0, $defaultToast->AutoHideSeconds);
        self::assertFalse($config->canView());
        self::assertFalse($config->canEdit());
        self::assertFalse($config->canCreate());
        $this->logInWithPermission('ADMIN');
        self::assertTrue($config->canView());
        self::assertTrue($config->canEdit());
        self::assertTrue($config->canCreate());
        self::assertFalse($config->canDelete());
        self::assertInstanceOf(SingleRecordAdmin::class, DeveloperToolsAdmin::create());
    }

    /**
     * Keeps the requested tab order and configures project and monitoring panels.
     */
    public function testTabStructure(): void
    {
        $this->mockVersions();
        $fields = DeveloperToolsConfig::currentRecord()->getCMSFields();
        self::assertSame(['Main', 'Monitoring', 'Toasts', 'Offline', 'Analysis'], $fields->fieldByName('Root')->getChildren()->column('Name'));
        self::assertSame(['OfflineHint', 'OfflineActive', 'OfflineStart', 'OfflineEnd', 'OfflineIPExceptions'],
            $fields->fieldByName('Root.Offline')->getChildren()->column('Name'));
        $monitoringFields = $fields->fieldByName('Root.Monitoring')->getChildren();
        self::assertSame(['MonitoringHint', 'MonitoringTabs'], $monitoringFields->column('Name'));
        self::assertInstanceOf(HintField::class, $monitoringFields->first());
        self::assertStringContainsString('Monitoring provides an overview', (string) $monitoringFields->first()->getContent());
        self::assertSame(['Info', 'Configuration', 'Notifications'],
            $fields->fieldByName('Root.Monitoring.MonitoringTabs')->getChildren()->column('Name'));
        self::assertInstanceOf(ListboxField::class, $fields->dataFieldByName(DeveloperToolsConfig::NOTIFY_MEMBERS));
        self::assertNull($fields->dataFieldByName('NotificationEmails'));
        $toastFields = $fields->fieldByName('Root.Toasts')->getChildren();
        self::assertSame('Overlays', $fields->fieldByName('Root.Toasts')->Title());
        self::assertSame('Overlay', ToastMessage::singleton()->i18n_singular_name());
        self::assertSame('Overlays', ToastMessage::singleton()->i18n_plural_name());
        self::assertSame(['ToastHint', 'ToastMessages'], $toastFields->column('Name'));
        self::assertInstanceOf(HintField::class, $toastFields->first());
        self::assertStringContainsString('maintenance notices', (string) $toastFields->first()->getContent());
        foreach (['environment', 'IP addresses', 'user groups', 'subgroups', 'start/end date and time'] as $filter) {
            self::assertStringContainsString($filter, (string) $toastFields->first()->getContent());
        }
        self::assertSame(
            ['ProjectInformation', 'ComposerPackages', 'Routes'],
            $fields->fieldByName('Root.Analysis')->getChildren()->column('Name')
        );
        $dashboard = $fields->dataFieldByName('DeveloperToolsDashboard');
        self::assertInstanceOf(DashboardField::class, $dashboard);
        self::assertSame('developertools', $dashboard->getDashboardKey());
        self::assertSame([ProjectInformationPanel::class, MonitoringWebsitesPanel::class], $dashboard->getDefaultPanels());
        self::assertSame([ProjectInformationPanel::class, MonitoringWebsitesPanel::class], $dashboard->getAllowedPanels());
        self::assertStringContainsString('bundle.css', (new MonitoringWebsitesPanel())->getCSS());
        self::assertNull($fields->dataFieldByName('Title'));
    }

    /**
     * Requires ADMIN for panel content, independently of the remote API token.
     */
    public function testDashboardPanelAccess(): void
    {
        $this->mockVersions();
        $url = (new ProjectInformationPanel())->getResourceURL();
        self::assertNotSame(200, $this->get($url)->getStatusCode());
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        self::assertNotSame(200, $this->get($url)->getStatusCode());
        $this->logInWithPermission('ADMIN');
        $response = $this->get($url);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertStringContainsString('8.3.0', $response->getBody());
        self::assertStringContainsString('&lt;database&gt;', $response->getBody());
        self::assertStringContainsString('no-store', $response->getHeader('Cache-Control'));
        $form = DeveloperToolsAdmin::create()->getEditForm();
        self::assertNotNull($form);
        self::assertNotNull($form->Fields()->dataFieldByName('ID'));
    }

    /**
     * Supplies deterministic local project versions without performing remote calls.
     */
    private function mockVersions(): void
    {
        $health = $this->createMock(HealthCheckService::class);
        $health->method('collect')->willReturn([]);
        Injector::inst()->registerService($health, HealthCheckService::class);
        $service = $this->createMock(ProjectInformationService::class);
        $service->method('getInformation')->willReturn([
            'php_version' => '8.3.0',
            'silverstripe_version' => '6.1.0',
            'database_version' => '<database>',
        ]);
        $service->expects($this->never())->method('getComposerPackages');
        Injector::inst()->registerService($service, ProjectInformationService::class);
    }
}

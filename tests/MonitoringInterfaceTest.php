<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tests;

use Clesson\Silverstripe\DeveloperTools\Admins\DeveloperToolsAdmin;
use Clesson\Silverstripe\DeveloperTools\Models\DeveloperToolsConfig;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoredWebsite;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoringCheck;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoringSnapshot;
use Clesson\Silverstripe\DeveloperTools\Panels\MonitoringWebsitesPanel;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringCheckService;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringTableRenderer;
use Clesson\Silverstripe\DeveloperTools\Services\ReleaseVersionService;
use Clesson\Silverstripe\DeveloperTools\Services\HealthCheckService;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReport;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringNotificationService;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\SecurityToken;

/**
 * Covers monitoring UI routes without performing customer or provider requests.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class MonitoringInterfaceTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected $autoFollowRedirection = false;

    private mixed $previousKey;

    /** @inheritdoc */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousKey = Environment::getEnv('ENCRYPTION_KEY');
        Environment::setEnv('ENCRYPTION_KEY', bin2hex(random_bytes(32)));
        $health = $this->createMock(HealthCheckService::class);
        $health->method('collect')->willReturn([]);
        Injector::inst()->registerService($health, HealthCheckService::class);
    }

    /** @inheritdoc */
    protected function tearDown(): void
    {
        Environment::setEnv('ENCRYPTION_KEY', $this->previousKey);
        parent::tearDown();
    }

    /** Requires ADMIN for details and does not expose a token or nested GridField. */
    public function testDetailsAuthorizationAndEmptyState(): void
    {
        $site = $this->website();
        $url = DeveloperToolsAdmin::singleton()->Link('monitoringDetails/' . $site->ID);
        self::assertNotSame(200, $this->get($url)->getStatusCode());
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        self::assertNotSame(200, $this->get($url)->getStatusCode());
        $this->logInWithPermission('ADMIN');
        $response = $this->get($url);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertStringContainsString('No successful inventory', $response->getBody());
        self::assertStringNotContainsString('ss-gridfield', $response->getBody());
        self::assertStringNotContainsString($site->ApiToken, $response->getBody());
        self::assertStringContainsString('no-store', $response->getHeader('Cache-Control'));
    }

    /** Rejects GET and invalid CSRF before the monitoring service is called. */
    public function testManualCheckRequiresPostAndCsrf(): void
    {
        $site = $this->website();
        $service = $this->createMock(MonitoringCheckService::class);
        $service->expects($this->never())->method('check');
        Injector::inst()->registerService($service, MonitoringCheckService::class);
        $this->logInWithPermission('ADMIN');
        SecurityToken::enable();
        $url = DeveloperToolsAdmin::singleton()->Link('monitoringCheck/' . $site->ID);
        self::assertSame(405, $this->get($url)->getStatusCode());
        self::assertSame(403, $this->post($url, ['SecurityID' => 'invalid'])->getStatusCode());
    }

    /** Exercises the valid POST route and its refreshed, credential-free response. */
    public function testManualCheckReturnsOutcomeAndVisibleFailure(): void
    {
        $notification = $this->createMock(MonitoringNotificationService::class);
        $notification->expects($this->never())->method('send');
        Injector::inst()->registerService($notification, MonitoringNotificationService::class);
        $site = $this->website();
        $service = $this->createMock(MonitoringCheckService::class);
        $service->expects($this->once())->method('check')->willReturnCallback(
            static function (MonitoredWebsite $website) use ($site): MonitoringCheck {
                self::assertSame((int) $site->ID, (int) $website->ID);
                $website->LastStatus = 'failed';
                $website->LastError = MonitoringCheck::FETCH_FAILED;
                $website->LastCheckedAt = '2026-01-01 12:00:00';
                $website->write();
                return MonitoringCheck::create(['Status' => 'failed']);
            }
        );
        Injector::inst()->registerService($service, MonitoringCheckService::class);
        $this->logInWithPermission('ADMIN');
        SecurityToken::enable();
        $this->session()->set('SecurityID', 'valid-monitoring-csrf');
        $url = DeveloperToolsAdmin::singleton()->Link('monitoringCheck/' . $site->ID);
        $response = $this->post($url, ['SecurityID' => 'valid-monitoring-csrf']);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $data = json_decode($response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('failed', $data['status']);
        self::assertStringContainsString('monitoring-status--unknown', $data['action_required_html']);
        self::assertSame('2026-01-01 12:00:00', $data['checked_at']);
        self::assertStringContainsString(MonitoringCheck::FETCH_FAILED, $data['html']);
        self::assertStringNotContainsString($site->ApiToken, $response->getBody());
        self::assertStringContainsString('no-store', $response->getHeader('Cache-Control'));
    }

    /** Renders all inventory item types in a four-column non-GridField table. */
    public function testDetailsRendersInventoryTable(): void
    {
        $site = $this->website();
        $snapshot = MonitoringSnapshot::create([
            'WebsiteID' => $site->ID, 'ObservedAt' => '2026-01-01 12:00:00',
            'Payload' => json_encode([
                'php_version' => '8.3.0', 'silverstripe_version' => '6.1.0',
                'database_version' => '10.11.0-MariaDB', 'database_product' => 'mariadb',
                'composer_packages' => [['name' => 'silverstripe/cms', 'version' => '6.1.0']],
            ], JSON_THROW_ON_ERROR),
        ]);
        $snapshot->write();
        $site->LatestSnapshotID = $snapshot->ID;
        $site->write();
        $health = [HealthReport::create()->result('project.error', 'Project check', 'error', 'Critical check failed.')];
        $check = MonitoringCheck::create([
            'WebsiteID' => $site->ID, 'SnapshotID' => $snapshot->ID,
            'StartedAt' => '2026-01-01 12:00:00', 'FinishedAt' => '2026-01-01 12:00:01',
            'Status' => 'changed', 'HealthResults' => json_encode($health),
        ]);
        $check->write();
        $this->logInWithPermission('ADMIN');
        $url = DeveloperToolsAdmin::singleton()->Link('monitoringDetails/' . $site->ID);
        $response = $this->get($url);
        self::assertSame(200, $response->getStatusCode());
        $html = $response->getBody();
        self::assertSame(4, substr_count($html, '<th scope="col">'));
        foreach (['PHP', 'Silverstripe CMS', 'MariaDB', 'silverstripe/cms', '8.3.0', '6.1.0'] as $value) {
            self::assertStringContainsString($value, $html);
        }
        self::assertStringNotContainsString('ss-gridfield', $html);
        self::assertStringNotContainsString($site->ApiToken, $html);
        self::assertStringContainsString('Critical check failed.', $html);
        $service = $this->createMock(ReleaseVersionService::class);
        $service->expects($this->exactly(3))->method('actionRequired')->willReturnOnConsecutiveCalls(true, false, null);
        Injector::inst()->registerService($service, ReleaseVersionService::class);
        foreach (['update', 'current', 'unknown'] as $status) {
            $icon = MonitoringTableRenderer::create()->renderActionRequired($site);
            self::assertStringContainsString('monitoring-status--' . $status, $icon);
            self::assertStringContainsString('role="img" aria-label="', $icon);
            self::assertStringContainsString('monitoring-status--failed', $icon);
            self::assertStringContainsString('Healthchecks: Error', $icon);
        }
        self::assertStringContainsString('Critical check failed.', (string) $check->getCMSFields()->fieldByName('Root.Main.HealthChecks')->getContent());
    }

    /** Renders an accessible overview with no embedded customer credentials. */
    public function testOverviewRendersControls(): void
    {
        $site = $this->website();
        $this->logInWithPermission('ADMIN');
        $form = DeveloperToolsAdmin::create()->getEditForm();
        $grid = $form->Fields()->dataFieldByName('MonitoringWebsites');
        $html = (string) $grid->FieldHolder();
        self::assertStringContainsString('data-monitoring-expand', $html);
        self::assertStringContainsString('data-monitoring-check', $html);
        self::assertStringContainsString('aria-expanded="false"', $html);
        self::assertStringContainsString('col-ActionRequired', $html);
        self::assertStringContainsString('monitoring-status--unknown', $html);
        self::assertStringNotContainsString('col-Active', $html);
        self::assertStringNotContainsString($site->ApiToken, $html);
    }

    /** Dashboard content is ADMIN-only and includes disabled sites without credentials. */
    public function testMonitoringPanelAccessAndWebsiteList(): void
    {
        $site = $this->website();
        $site->Name = '<Customer>';
        $site->Active = false;
        $site->write();
        $url = (new MonitoringWebsitesPanel())->getResourceURL();
        self::assertNotSame(200, $this->get($url)->getStatusCode());
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        self::assertNotSame(200, $this->get($url)->getStatusCode());
        $this->logInWithPermission('ADMIN');
        $response = $this->get($url);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertStringContainsString('&lt;Customer&gt;', $response->getBody());
        self::assertStringContainsString('https://example.com', $response->getBody());
        self::assertStringContainsString('monitoring-status--unknown', $response->getBody());
        self::assertStringContainsString('role="img"', $response->getBody());
        self::assertStringNotContainsString($site->ApiToken, $response->getBody());
        self::assertStringContainsString('no-store', $response->getHeader('Cache-Control'));
    }

    /** An empty configuration shows a meaningful panel state. */
    public function testMonitoringPanelEmptyState(): void
    {
        $this->logInWithPermission('ADMIN');
        $response = $this->get((new MonitoringWebsitesPanel())->getResourceURL());
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('No websites have been configured.', $response->getBody());
    }

    /** Creates only a configured website; no network check is executed. */
    private function website(): MonitoredWebsite
    {
        $site = MonitoredWebsite::create([
            'Name' => 'Customer', 'BaseURL' => 'https://example.com',
            'ApiToken' => bin2hex(random_bytes(32)), 'Active' => true,
            'ConfigID' => DeveloperToolsConfig::currentRecord()->ID,
        ]);
        $site->write();
        return $site;
    }
}

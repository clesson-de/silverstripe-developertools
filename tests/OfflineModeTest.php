<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tests;

use Clesson\Silverstripe\DeveloperTools\Constants\OfflineField as F;
use Clesson\Silverstripe\DeveloperTools\Middleware\OfflineMiddleware;
use Clesson\Silverstripe\DeveloperTools\Models\DeveloperToolsConfig;
use Clesson\Silverstripe\DeveloperTools\Models\OfflinePage;
use Clesson\Silverstripe\DeveloperTools\Services\OfflinePageExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use SilverStripe\Admin\AdminRootController;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\TrustedProxyMiddleware;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;

/**
 * Exercises published static export, maintenance gating, recovery routes and IP exceptions.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class OfflineModeTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected $autoFollowRedirection = false;

    /** Keeps exported HTML in an isolated, disposable asset store. */
    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('OfflineModeTest');
        $this->logInWithPermission('ADMIN');
    }

    /** Restores the actual asset store through the test injector boundary. */
    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    /** A build creates an English, public, published export without switching maintenance on. */
    public function testDefaultPageIsCreatedOnlyOnce(): void
    {
        i18n::with_locale('de_DE', fn() => OfflinePage::singleton()->requireDefaultRecords());
        $page = OfflinePageExporter::create()->publishedPage();
        self::assertNotNull($page);
        self::assertSame('Website maintenance', $page->Title);
        self::assertStringContainsString('scheduled maintenance', $page->Content);
        self::assertStringContainsString('Thank you for your patience.', $page->Content);
        self::assertFalse((bool) $page->ShowInMenus);
        self::assertFalse((bool) $page->ShowInSearch);
        self::assertSame('Anyone', $page->CanViewType);
        self::assertStringContainsString('scheduled maintenance', OfflinePageExporter::create()->content($page));
        self::assertFalse((bool) DeveloperToolsConfig::currentRecord()->getField(F::ACTIVE));
        OfflinePage::singleton()->requireDefaultRecords();
        self::assertSame(1, Versioned::get_by_stage(OfflinePage::class, Versioned::DRAFT)->count());
        self::assertSame($page->ID, OfflinePageExporter::create()->publishedPage()->ID);
    }

    /** Existing pages, including unpublished edits, are never replaced or published by a build. */
    #[DataProvider('existingPageStages')]
    public function testDefaultRecordsPreserveExistingPage(bool $published): void
    {
        $page = $this->page($published);
        $exporter = OfflinePageExporter::create();
        $export = $exporter->content($page);
        $page->Content = '<p>Unpublished custom maintenance text</p>';
        $page->write();
        $version = $page->Version;
        OfflinePage::singleton()->requireDefaultRecords();
        $draft = Versioned::get_by_stage(OfflinePage::class, Versioned::DRAFT)->first();
        self::assertSame($page->ID, $draft->ID);
        self::assertSame((int) $version, (int) $draft->Version);
        self::assertSame($page->Content, $draft->Content);
        self::assertSame($export, $exporter->content($page));
        self::assertSame($published, $exporter->publishedPage() !== null);
    }

    /** Both existing drafts and live documents prevent default creation. */
    public static function existingPageStages(): array
    {
        return [[false], [true]];
    }

    /** Publishing exports, draft saves do not, and republishing replaces the static content. */
    public function testPublishedStaticExport(): void
    {
        $page = $this->page(false);
        $member = Security::getCurrentUser();
        $exporter = OfflinePageExporter::create();
        self::assertNull($exporter->content($page));
        $page->publishSingle();
        $html = $exporter->content($page);
        self::assertStringContainsString('<html', $html);
        self::assertStringContainsString('Maintenance &lt;notice&gt;', $html);
        self::assertStringContainsString('<p>Published maintenance text</p>', $html);
        self::assertStringNotContainsString('SilverStripeNavigator', $html);
        self::assertStringNotContainsString('toast-overlays', $html);
        self::assertSame($member, Security::getCurrentUser());
        $page->Content = '<p>Draft-only maintenance text</p>';
        $page->write();
        self::assertSame($html, $exporter->content($page));
        $page->publishSingle();
        self::assertStringContainsString('Draft-only maintenance text', $exporter->content($page));
        $page->doUnpublish();
        self::assertNull($exporter->content($page));
    }

    /** CMS uniqueness covers drafts as well as published pages; access must stay public. */
    public function testUniquePublicPage(): void
    {
        self::assertTrue(OfflinePage::create()->canCreate());
        $page = $this->page(false);
        self::assertFalse(OfflinePage::create()->canCreate());
        self::assertInstanceOf(HTMLEditorField::class, $page->getCMSFields()->dataFieldByName('Content'));
        self::assertFalse(OfflinePage::create(['Title' => 'Second'])->validate()->isValid());
        self::assertTrue($page->validate()->isValid());
        $page->CanViewType = 'LoggedInUsers';
        self::assertFalse($page->validate()->isValid());
        self::assertFalse($page->canPublish());
        self::assertFalse($page->canAddChildren());
    }

    /** Activation requires a published export; invalid rules or date windows are rejected. */
    public function testSettingsValidation(): void
    {
        $config = DeveloperToolsConfig::currentRecord();
        self::assertFalse((bool) $config->getField(F::ACTIVE));
        $config->setField(F::ACTIVE, true);
        self::assertFalse($config->validate()->isValid());
        $page = $this->page(false);
        self::assertFalse($config->validate()->isValid());
        $page->publishSingle();
        self::assertTrue($config->validate()->isValid());
        $config->setField(F::IP_EXCEPTIONS, 'invalid');
        self::assertFalse($config->validate()->isValid());
        $config->setField(F::IP_EXCEPTIONS, "192.0.2.1, 2001:db8::/32\n192.0.2.5-192.0.2.10");
        self::assertTrue($config->validate()->isValid());
        $config->setField(F::START, '2026-10-29 12:00:00');
        $config->setField(F::END, '2026-10-29 12:00:00');
        self::assertFalse($config->validate()->isValid());
        $config->setField(F::END, '2026-10-29 12:00:01');
        self::assertTrue($config->validate()->isValid());
    }

    /** Blocking applies to every method and dynamic endpoint, including authenticated administrators. */
    #[DataProvider('blockedRequests')]
    public function testBlockedRequests(string $method, string $path): void
    {
        $page = $this->page();
        $this->enable();
        $request = new HTTPRequest($method, $path, ['flush' => 'all', 'stage' => 'Stage']);
        $request->addHeader('X-Requested-With', 'XMLHttpRequest');
        $request->setIP('192.0.2.1');
        $response = OfflineMiddleware::create()->process($request, function () {
            self::fail('Blocked requests must not reach routing or execute writes.');
        });
        self::assertSame(503, $response->getStatusCode());
        self::assertSame($method === 'HEAD' ? '' : OfflinePageExporter::create()->content($page), $response->getBody());
        self::assertSame('private, no-store', $response->getHeader('Cache-Control'));
        self::assertSame('300', $response->getHeader('Retry-After'));
        self::assertNull($response->getHeader('Location'));
        self::assertSame('text/html; charset=utf-8', $response->getHeader('Content-Type'));
    }

    /** No method, URL suffix, query flag or CMS-like prefix implicitly bypasses the gate. */
    public static function blockedRequests(): array
    {
        return [
            ['GET', ''], ['GET', 'ordinary-page'], ['GET', 'api/resource'], ['GET', 'graphql'],
            ['GET', 'developer-tools/health'], ['GET', 'dev/build'], ['POST', 'contact/send'],
            ['PUT', 'api/resource'], ['DELETE', 'api/resource/1'], ['OPTIONS', 'api/resource'],
            ['HEAD', 'ordinary-page'], ['GET', 'administrator'], ['GET', 'assets-fake/route'],
            ['GET', 'ordinary-page.css'], ['GET', 'Security-fake/login'],
        ];
    }

    /** Recovery resources and complete authentication routes retain normal request handling. */
    #[DataProvider('recoveryRequests')]
    public function testRecoveryRoutes(string $path): void
    {
        $this->page();
        $this->enable();
        $normal = new HTTPResponse('Recovery route', 200);
        $request = new HTTPRequest('POST', $path);
        self::assertSame($normal, OfflineMiddleware::create()->process($request, fn() => $normal));
    }

    /** Includes CMS login submissions, password reset and core resources. */
    public static function recoveryRequests(): array
    {
        return [
            ['admin'], ['admin/developer-tools'], ['admin/pages'], ['Security/login'],
            ['Security/lostpassword'], ['CMSSecurity/login'], ['CMSSecurity/LoginForm'],
            ['_resources/example/client.js'], ['assets/image.jpg'],
        ];
    }

    /** Configured CMS and login prefixes replace assumptions about default routes. */
    public function testCustomRecoveryRoutes(): void
    {
        $this->page();
        $this->enable();
        $rules = array_filter(Director::config()->get('rules'), fn($controller) => $controller !== AdminRootController::class);
        Config::modify()->set(Director::class, 'rules', ['manage' => AdminRootController::class] + $rules);
        Config::modify()->set(Security::class, 'login_url', 'account/sign-in');
        foreach (['manage/developer-tools', 'account/sign-in/LoginForm'] as $path) {
            $response = OfflineMiddleware::create()->process(new HTTPRequest('GET', $path), fn() => new HTTPResponse('Normal'));
            self::assertSame(200, $response->getStatusCode());
        }
    }

    /** IP exceptions include IPv6, networks and ranges; no rules means no exceptions. */
    #[DataProvider('ipExceptions')]
    public function testIPExceptions(string $rules, string $ip, bool $allowed): void
    {
        $this->page();
        $this->enable([F::IP_EXCEPTIONS => $rules]);
        $request = new HTTPRequest('GET', 'page');
        $request->setIP($ip);
        $response = OfflineMiddleware::create()->process($request, fn() => new HTTPResponse('Normal'));
        self::assertSame($allowed ? 200 : 503, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeader('Cache-Control'));
    }

    /** Empty exceptions deliberately differ from the overlay filter's empty-match semantics. */
    public static function ipExceptions(): array
    {
        return [
            ['', '192.0.2.1', false], ['192.0.2.1', '192.0.2.1', true],
            ['192.0.2.1', '192.0.2.2', false], ['192.0.2.0/24', '192.0.2.42', true],
            ['192.0.2.1-192.0.2.10', '192.0.2.10', true],
            ["192.0.2.1\n2001:db8::/32", '2001:db8::1', true],
            ['2001:db8::/32', '2001:db9::1', false],
        ];
    }

    /** Only a configured trusted proxy may supply the client address used for exceptions. */
    public function testTrustedProxyExceptions(): void
    {
        $this->page();
        $this->enable([F::IP_EXCEPTIONS => '2001:db8::1']);
        $proxy = new TrustedProxyMiddleware();
        $proxy->setTrustedProxyIPs('127.0.0.1');
        foreach (['127.0.0.1' => 200, '192.0.2.1' => 503] as $source => $expected) {
            $request = new HTTPRequest('GET', 'page');
            $request->setIP($source);
            $request->addHeader('X-Forwarded-For', '2001:db8::1');
            $response = $proxy->process($request, fn($request) =>
                OfflineMiddleware::create()->process($request, fn() => new HTTPResponse('Normal')));
            self::assertSame($expected, $response->getStatusCode());
        }
    }

    /** The start is included and end excluded, without relying on cron or an open browser. */
    #[DataProvider('scheduleWindows')]
    public function testSchedule(?string $start, ?string $end, bool $blocked): void
    {
        $this->page();
        $this->enable([F::START => $start, F::END => $end]);
        $response = DBDatetime::withFixedNow('2026-10-29 12:00:00', fn() =>
            OfflineMiddleware::create()->process(new HTTPRequest('GET', 'page'), fn() => new HTTPResponse('Normal')));
        self::assertSame($blocked ? 503 : 200, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeader('Cache-Control'));
    }

    /** Optional bounds cover immediate activation, future activation and expiry. */
    public static function scheduleWindows(): array
    {
        return [
            [null, null, true], ['2026-10-29 12:00:00', null, true],
            ['2026-10-29 12:00:01', null, false], [null, '2026-10-29 12:00:00', false],
            [null, '2026-10-29 12:00:01', true],
        ];
    }

    /** Missing or withdrawn documents do not silently reopen frontend traffic. */
    public function testMissingExportStaysOffline(): void
    {
        $page = $this->page();
        $this->enable();
        OfflinePageExporter::create()->remove($page);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        Injector::inst()->registerService($logger, LoggerInterface::class);
        $response = OfflineMiddleware::create()->process(new HTTPRequest('GET', 'page'), function () {
            self::fail('An unavailable export must not reopen the website.');
        });
        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('Service temporarily unavailable', $response->getBody());
    }

    /** Disabling the feature leaves ordinary responses and cache headers untouched. */
    public function testDisabledMode(): void
    {
        $this->page();
        $config = $this->enable();
        $config->setField(F::ACTIVE, false);
        $config->write();
        $normal = (new HTTPResponse('Normal'))->addHeader('Cache-Control', 'public, max-age=60');
        self::assertSame($normal, OfflineMiddleware::create()->process(new HTTPRequest('GET', 'page'), fn() => $normal));
        self::assertSame('public, max-age=60', $normal->getHeader('Cache-Control'));
    }

    /** The real Director stack applies the gate after trusted proxy resolution and before routing. */
    public function testRegisteredMiddlewareAndActualAdminLogin(): void
    {
        $this->page();
        $this->enable();
        $classes = array_map(fn($middleware) => $middleware::class, array_values(Director::singleton()->getMiddlewares()));
        self::assertContains(OfflineMiddleware::class, $classes);
        self::assertLessThan(array_search(OfflineMiddleware::class, $classes, true),
            array_search(TrustedProxyMiddleware::class, $classes, true));
        $this->logOut();
        $blocked = $this->get('nonexistent-offline-test');
        self::assertSame(503, $blocked->getStatusCode());
        self::assertStringContainsString('no-store', $blocked->getHeader('Cache-Control'));
        self::assertSame(503, $this->post('api/offline-test', ['example' => 'value'])->getStatusCode());
        self::assertSame(200, $this->get('Security/login')->getStatusCode());
        self::assertContains($this->get('admin')->getStatusCode(), [301, 302, 303]);
    }

    /** Creates the only maintenance document without changing production content. */
    private function page(bool $publish = true): OfflinePage
    {
        $page = OfflinePage::create(['Title' => 'Maintenance <notice>',
            'Content' => '<p>Published maintenance text</p>', 'URLSegment' => 'offline-test']);
        $page->write();
        if ($publish) {
            $page->publishSingle();
        }
        return $page;
    }

    /** Uses normal validation so tests cannot enable an invalid maintenance configuration. */
    private function enable(array $fields = []): DeveloperToolsConfig
    {
        $config = DeveloperToolsConfig::currentRecord();
        $config->update($fields + [F::ACTIVE => true]);
        $config->write();
        return $config;
    }
}

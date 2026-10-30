<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tests;

use Clesson\Silverstripe\DeveloperTools\Helpers\ToastHTML;
use Clesson\Silverstripe\DeveloperTools\Middleware\ToastMiddleware;
use Clesson\Silverstripe\DeveloperTools\Models\DeveloperToolsConfig;
use Clesson\Silverstripe\DeveloperTools\Models\ToastMessage;
use Clesson\Silverstripe\DeveloperTools\Services\ToastRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Admin\AdminRootController;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Control\Middleware\TrustedProxyMiddleware;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Security\Security;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Request-aware frontend delivery, safe HTML rendering and actual middleware registration.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class ToastMiddlewareTest extends FunctionalTest
{
    protected $usesDatabase = true;

    #[DataProvider('groupAudienceProvider')]
    public function testGroupAudienceFiltering(string $membership, bool $visible, bool $matchingIP): void
    {
        $parent = Group::create(['Title' => 'Private audience parent']);
        $parent->write();
        $child = Group::create(['Title' => 'Private audience child', 'ParentID' => $parent->ID]);
        $child->write();
        $other = Group::create(['Title' => 'Private alternative audience']);
        $other->write();
        $toast = $this->toast(['Message' => 'Group-restricted notice', 'IPRanges' => '192.0.2.1']);
        $toast->Groups()->add($parent);
        $toast->Groups()->add($other);
        $this->logOut();
        if ($membership !== 'anonymous') {
            $member = Member::create(['FirstName' => 'Audience visitor']);
            $member->write();
            if ($membership !== 'none') {
                $member->Groups()->add(match ($membership) {
                    'parent' => $parent, 'child' => $child, 'other' => $other,
                });
            }
            Security::setCurrentUser($member);
        }
        try {
            $request = new HTTPRequest('GET', 'page');
            $request->setIP($matchingIP ? '192.0.2.1' : '198.51.100.1');
            $response = ToastMiddleware::create()->process($request, fn() => $this->page());
            self::assertSame($visible, str_contains($response->getBody(), 'Group-restricted notice'));
            self::assertSame('private, no-store', $response->getHeader('Cache-Control'));
            self::assertStringNotContainsString('Private audience', $response->getBody());
            self::assertStringNotContainsString('Private alternative', $response->getBody());
        } finally {
            Security::setCurrentUser(null);
        }
    }

    public static function groupAudienceProvider(): array
    {
        return [
            ['anonymous', false, true], ['none', false, true],
            ['parent', true, true], ['child', true, true], ['other', true, true],
            ['parent', false, false],
        ];
    }

    #[DataProvider('displayPeriodProvider')]
    public function testDisplayPeriodFiltering(?string $start, ?string $end, bool $visible): void
    {
        DBDatetime::set_mock_now('2026-10-29 10:00:00');
        try {
            $this->toast(['StartDate' => $start, 'EndDate' => $end, 'Message' => 'Scheduled notice']);
            $response = ToastMiddleware::create()->process(new HTTPRequest('GET', 'page'), fn() => $this->page());
            self::assertSame($visible, str_contains($response->getBody(), 'Scheduled notice'));
            self::assertSame('private, no-store', $response->getHeader('Cache-Control'));
        } finally {
            DBDatetime::clear_mock_now();
        }
    }

    public static function displayPeriodProvider(): array
    {
        return [
            [null, null, true],
            ['2026-10-29 09:59:59', null, true],
            ['2026-10-29 10:00:00', null, true],
            ['2026-10-29 10:00:01', null, false],
            [null, '2026-10-29 09:59:59', false],
            [null, '2026-10-29 10:00:00', false],
            [null, '2026-10-29 10:00:01', true],
            ['2026-10-29 09:00:00', '2026-10-29 11:00:00', true],
            ['2026-10-29 10:00:00', '2026-10-29 10:00:00', false],
        ];
    }

    public function testScheduledMessageBecomesVisibleAndExpiresWithoutChangingItsActiveState(): void
    {
        $this->toast(['StartDate' => '2026-10-29 10:00:00', 'EndDate' => '2026-10-29 11:00:00']);
        try {
            foreach (['09:59:59' => false, '10:00:00' => true, '11:00:00' => false] as $time => $visible) {
                DBDatetime::set_mock_now('2026-10-29 ' . $time);
                $response = ToastMiddleware::create()->process(new HTTPRequest('GET', 'page'), fn() => $this->page());
                self::assertSame($visible, str_contains($response->getBody(), ToastRenderer::OVERLAY_ID));
            }
        } finally {
            DBDatetime::clear_mock_now();
        }
    }

    #[DataProvider('typeProvider')]
    public function testEachMessageAppearsOnceInItsConfiguredType(string $type, string $role): void
    {
        $this->toast(['Type' => $type, 'Message' => 'Single notice']);
        $html = ToastMiddleware::create()->process(new HTTPRequest('GET', 'page'), fn() => $this->page())->getBody();
        self::assertSame(1, substr_count($html, '<section '));
        self::assertSame(1, substr_count($html, 'class="dt-toast dt-toast--' . $type . '"'));
        self::assertSame(1, substr_count($html, 'role="' . $role . '"'));
        self::assertSame(1, substr_count($html, 'Single notice'));
        self::assertStringNotContainsString('<strong>', $html);
        self::assertSame(1, substr_count($html, 'dt-toast__close'));
    }

    public static function typeProvider(): array
    {
        return [['info', 'status'], ['warning', 'status'], ['alert', 'alert']];
    }

    #[DataProvider('titleProvider')]
    public function testOptionalTitleIsRenderedAsPlainText(string $title, string $expected): void
    {
        $this->toast(['Title' => $title, 'Removable' => false]);
        $html = ToastMiddleware::create()->process(new HTTPRequest('GET', 'page'), fn() => $this->page())->getBody();
        if ($title === '') {
            self::assertStringNotContainsString('dt-toast__heading', $html);
            self::assertStringNotContainsString('<strong>', $html);
            return;
        }
        self::assertStringContainsString('<strong>' . $expected . '</strong>', $html);
        self::assertSame(1, substr_count($html, '<strong>'));
    }

    public static function titleProvider(): array
    {
        return [
            ['', ''],
            ['Maintenance', 'Maintenance'],
            ['<b>Title</b> **bold** & text', '&lt;b&gt;Title&lt;/b&gt; **bold** &amp; text'],
            ['0', '0'],
        ];
    }

    public function testFilteringAndRenderingForAnonymousVisitors(): void
    {
        $this->logOut();
        $this->toast(['Message' => '**Public notice**']);
        $this->toast(['Message' => 'Allowed IP', 'IPRanges' => '192.0.2.0/24', 'Removable' => false]);
        $this->toast(['Message' => 'Hidden IP', 'IPRanges' => '198.51.100.1']);
        $this->toast(['Message' => 'Disabled notice', 'Active' => false]);
        $request = new HTTPRequest('GET', 'some-page');
        $request->setIP('192.0.2.5');
        $original = $this->page()->addHeader('Content-Length', '1')->addHeader('ETag', '"old"');
        $response = ToastMiddleware::create()->process($request, static fn() => $original);
        $html = $response->getBody();
        self::assertStringContainsString('<strong>Public notice</strong>', $html);
        self::assertStringContainsString('Allowed IP', $html);
        self::assertStringNotContainsString('Hidden IP', $html);
        self::assertStringNotContainsString('Disabled notice', $html);
        self::assertStringNotContainsString('192.0.2.0', $html);
        self::assertStringNotContainsString('bundle.js', $html);
        self::assertStringContainsString('toasts.js', $html);
        self::assertStringContainsString('toasts.css', $html);
        self::assertSame(1, substr_count($html, 'dt-toast__close'));
        self::assertSame(1, substr_count($html, 'id="' . ToastRenderer::OVERLAY_ID . '"'));
        self::assertSame('private, no-store', $response->getHeader('Cache-Control'));
        self::assertNull($response->getHeader('Content-Length'));
        self::assertNull($response->getHeader('ETag'));
        self::assertLessThan(stripos($html, '</body>'), strpos($html, ToastRenderer::OVERLAY_ID));
        $again = ToastMiddleware::create()->process($request, static fn() => $response);
        self::assertSame($html, $again->getBody());
    }

    public function testNoIPMatchStillDisablesSharedCachingWithoutLeakingMessagesOrAssets(): void
    {
        $this->toast(['Message' => 'Restricted notice', 'IPRanges' => '198.51.100.1']);
        $request = new HTTPRequest('GET', 'some-page');
        $request->setIP('192.0.2.1');
        $page = $this->page()->addHeader('Last-Modified', 'Thu, 01 Jan 2026 00:00:00 GMT')
            ->addHeader('Surrogate-Control', 'max-age=3600')->addHeader('Expires', 'Fri, 01 Jan 2027 00:00:00 GMT');
        $response = ToastMiddleware::create()->process($request, static fn() => $page);
        self::assertSame('<!doctype html><html><head></head><body><main>Page</main></body></html>', $response->getBody());
        self::assertSame('private, no-store', $response->getHeader('Cache-Control'));
        self::assertNull($response->getHeader('Last-Modified'));
        self::assertNull($response->getHeader('Surrogate-Control'));
        self::assertNull($response->getHeader('Expires'));
    }

    public function testNoActiveCandidatesLeavePageAndCachingUntouched(): void
    {
        $this->toast(['Active' => false]);
        $page = $this->page();
        $response = ToastMiddleware::create()->process(new HTTPRequest('GET', 'page'), static fn() => $page);
        self::assertSame('public, max-age=3600', $response->getHeader('Cache-Control'));
        self::assertStringNotContainsString(ToastRenderer::OVERLAY_ID, $response->getBody());
    }

    public function testDifferentEnvironmentPreservesCaching(): void
    {
        $this->toast(['Environment' => 'live']);
        $page = $this->page();
        $response = ToastMiddleware::create()->process(new HTTPRequest('GET', 'page'), static fn() => $page);
        self::assertSame('public, max-age=3600', $response->getHeader('Cache-Control'));
        self::assertStringNotContainsString(ToastRenderer::OVERLAY_ID, $response->getBody());
    }

    #[DataProvider('environmentProvider')]
    public function testEnvironmentFiltering(string $environment, string $visible, string $hidden): void
    {
        $this->toast(['Message' => 'Everywhere', 'Environment' => 'all']);
        $this->toast(['Message' => 'Development notice', 'Environment' => 'dev']);
        $this->toast(['Message' => 'Production notice', 'Environment' => 'live']);
        $kernel = Injector::inst()->get(Kernel::class);
        $previous = $kernel->getEnvironment();
        try {
            $kernel->setEnvironment($environment);
            $response = ToastMiddleware::create()->process(new HTTPRequest('GET', 'page'), fn() => $this->page());
            self::assertStringContainsString('Everywhere', $response->getBody());
            self::assertStringContainsString($visible, $response->getBody());
            self::assertStringNotContainsString($hidden, $response->getBody());
        } finally {
            $kernel->setEnvironment($previous);
        }
    }

    public static function environmentProvider(): array
    {
        return [
            ['dev', 'Development notice', 'Production notice'],
            ['live', 'Production notice', 'Development notice'],
            ['test', 'Everywhere', 'Development notice'],
        ];
    }

    public function testTrustedProxyAndOriginalPathAreRespected(): void
    {
        $this->toast(['Message' => 'Proxy visitor', 'IPRanges' => '2001:db8::1']);
        $request = new HTTPRequest('GET', 'page');
        $request->setIP('127.0.0.1');
        $request->addHeader('X-Forwarded-For', '2001:db8::1');
        $proxy = new TrustedProxyMiddleware();
        $proxy->setTrustedProxyIPs('127.0.0.1');
        $response = ToastMiddleware::create()->process($request, fn($request) => $proxy->process($request, fn() => $this->page()));
        self::assertStringContainsString('Proxy visitor', $response->getBody());
        $request = new HTTPRequest('GET', 'page');
        $request->setIP('192.0.2.1');
        $request->addHeader('X-Forwarded-For', '2001:db8::1');
        $response = ToastMiddleware::create()->process($request, fn($request) => $proxy->process($request, fn() => $this->page()));
        self::assertStringNotContainsString('Proxy visitor', $response->getBody());
    }

    #[DataProvider('excludedProvider')]
    public function testExcludedRequests(string $method, string $path, bool $ajax): void
    {
        $this->toast();
        $request = new HTTPRequest($method, $path);
        if ($ajax) {
            $request->addHeader('X-Requested-With', 'XMLHttpRequest');
        }
        $page = $this->page();
        $body = $page->getBody();
        $response = ToastMiddleware::create()->process($request, static function ($request) use ($page) {
            $request->shift(1);
            return $page;
        });
        self::assertSame($body, $response->getBody());
        self::assertSame('public, max-age=3600', $response->getHeader('Cache-Control'));
    }

    public static function excludedProvider(): array
    {
        return [
            ['GET', 'admin/developer-tools', false], ['GET', 'Security/login', false],
            ['GET', 'dev/build', false], ['GET', 'api/resource', false],
            ['GET', 'graphql', false], ['GET', 'developer-tools/project-information', false],
            ['GET', 'admin', false], ['GET', 'page', true],
            ['POST', 'page', false], ['HEAD', 'page', false],
        ];
    }

    public function testCustomAdminAndLoginPaths(): void
    {
        $this->toast();
        Config::modify()->set(AdminRootController::class, 'url_base', 'manage');
        Config::modify()->set(Director::class, 'rules', ['manage' => AdminRootController::class]);
        Config::modify()->set(Security::class, 'login_url', 'members/signin');
        foreach (['manage', 'manage/developer-tools', 'members/signin'] as $path) {
            $response = ToastMiddleware::create()->process(new HTTPRequest('GET', $path), fn() => $this->page());
            self::assertStringNotContainsString(ToastRenderer::OVERLAY_ID, $response->getBody());
        }
    }

    public function testNonPageResponsesRemainUntouched(): void
    {
        $this->toast();
        foreach ([
            $this->page()->setStatusCode(404),
            $this->page()->setStatusCode(302),
            $this->page()->addHeader('Content-Type', 'application/json'),
            $this->page()->addHeader('Content-Disposition', 'attachment'),
            $this->page()->addHeader('Content-Encoding', 'gzip'),
            HTTPResponse::create('<div>fragment</div>')->addHeader('Content-Type', 'text/html'),
        ] as $page) {
            $body = $page->getBody();
            $headers = $page->getHeaders();
            $response = ToastMiddleware::create()->process(new HTTPRequest('GET', 'page'), static fn() => $page);
            self::assertSame($body, $response->getBody());
            self::assertSame($headers, $response->getHeaders());
        }
    }

    public function testSafeMarkdownPresentation(): void
    {
        $this->toast(['Type' => 'alert', 'Message' => '**Important** [link](https://example.com) <script>alert(1)</script>'
            . '<img src="x" onerror="alert(1)"><a href="javascript:alert">bad</a>'
            . '<div style="position:fixed" id="escape"><iframe src="https://example.com"></iframe>Text</div>']);
        $response = ToastMiddleware::create()->process(new HTTPRequest('GET', 'page'), fn() => $this->page());
        $html = $response->getBody();
        self::assertStringContainsString('<strong>Important</strong>', $html);
        self::assertStringContainsString('href="https://example.com"', $html);
        self::assertStringContainsString('role="alert"', $html);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('onerror', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('position:fixed', $html);
        self::assertStringNotContainsString('id="escape"', $html);
        self::assertStringNotContainsString('data:', ToastHTML::sanitise('<img src="data:image/svg+xml,test">'));
    }

    public function testRegisteredMiddlewareWrapsCoreCachingAndServesRoutedPages(): void
    {
        $middlewares = array_values(Director::singleton()->getMiddlewares());
        $classes = array_map(static fn($middleware) => $middleware::class, $middlewares);
        self::assertContains(ToastMiddleware::class, $classes);
        self::assertLessThan(array_search(HTTPCacheControlMiddleware::class, $classes, true), array_search(ToastMiddleware::class, $classes, true));
        Config::modify()->set(Director::class, 'rules', ['toast-test' => ToastTestPageController::class] + Director::config()->get('rules'));
        $this->toast(['Message' => 'Routed notice']);
        $this->logOut();
        $response = $this->get('toast-test');
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertStringContainsString('Routed notice', $response->getBody());
        self::assertSame('private, no-store', $response->getHeader('Cache-Control'));
    }

    private function toast(array $values = []): ToastMessage
    {
        $toast = ToastMessage::create($values + ['Message' => 'Notice', 'SettingsID' => DeveloperToolsConfig::currentRecord()->ID]);
        $toast->write();
        return $toast;
    }

    private function page(): HTTPResponse
    {
        return HTTPResponse::create('<!doctype html><html><head></head><body><main>Page</main></body></html>')
            ->addHeader('Content-Type', 'text/html; charset=utf-8')->addHeader('Cache-Control', 'public, max-age=3600');
    }
}

/**
 * Anonymous test-only frontend route used to exercise the registered middleware stack.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class ToastTestPageController extends Controller implements TestOnly
{
    public function index(): HTTPResponse
    {
        return HTTPResponse::create('<!doctype html><html><head></head><body>Routed page</body></html>')
            ->addHeader('Content-Type', 'text/html')
            ->addHeader('Cache-Control', 'public, max-age=3600');
    }
}

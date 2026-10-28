<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tests;

use Clesson\Silverstripe\DeveloperTools\Admins\DeveloperToolsAdmin;
use Clesson\Silverstripe\DeveloperTools\Constants\ProjectInformationField;
use Clesson\Silverstripe\DeveloperTools\Controllers\ProjectInformationController;
use Clesson\Silverstripe\DeveloperTools\Models\DeveloperToolsConfig;
use Clesson\Silverstripe\DeveloperTools\Services\ProjectInformationService;
use Clesson\Silverstripe\DeveloperTools\Services\HealthCheckService;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Forms\ReadonlyField;

/**
 * Covers the routed JSON endpoint, token isolation and CMS information fields.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class ProjectInformationTest extends FunctionalTest
{
    /** Test endpoint path. */
    private const URL = '/developer-tools/project-information';

    /** Fixed version values used without database access. */
    private const INFORMATION = [
        'php_version' => '8.3.0',
        'silverstripe_version' => '6.1.0',
        'database_version' => '10.11.0-MariaDB',
    ];

    /** Installed dependency inventory returned by the service mock. */
    private const PACKAGES = [
        ['name' => 'silverstripe/framework', 'version' => '6.1.0'],
    ];

    private mixed $previousToken;

    private string $token;

    /** @inheritdoc */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousToken = Environment::getEnv(ProjectInformationController::TOKEN_ENV);
        $this->token = bin2hex(random_bytes(32));
        Environment::setEnv(ProjectInformationController::TOKEN_ENV, $this->token);
    }

    /** @inheritdoc */
    protected function tearDown(): void
    {
        Environment::setEnv(ProjectInformationController::TOKEN_ENV, $this->previousToken);
        parent::tearDown();
    }

    /**
     * Leaves the endpoint disabled without a configured secret.
     */
    public function testUnconfiguredEndpointIsDisabled(): void
    {
        $this->mockInformationService(false);
        foreach ([false, '', '   '] as $token) {
            Environment::setEnv(ProjectInformationController::TOKEN_ENV, $token);
            $response = $this->get(self::URL);
            self::assertSame(503, $response->getStatusCode());
            self::assertArrayHasKey('error', json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));
            self::assertStringContainsString('no-store', $response->getHeader('Cache-Control'));
        }
    }

    /**
     * Rejects missing, malformed and incorrect credentials before collecting data.
     */
    public function testInvalidCredentialsAreRejected(): void
    {
        $this->mockInformationService(false);
        foreach (['', 'Bearer wrong', 'Basic ' . $this->token, 'Bearer ', 'Bearer ' . $this->token . ' extra'] as $header) {
            $response = $this->get(self::URL, null, ['Authorization' => $header]);
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('Bearer realm="project-information"', $response->getHeader('WWW-Authenticate'));
            self::assertArrayNotHasKey(ProjectInformationField::PHP_VERSION, json_decode($response->getBody(), true));
        }
    }

    /**
     * Does not accept query-string tokens or spoofed origin headers.
     */
    public function testQueryTokensAndOriginHeadersDoNotAuthorize(): void
    {
        $this->mockInformationService(false);
        $response = $this->get(self::URL . '?token=' . $this->token, null, [
            'Origin' => 'https://trusted.example',
            'Referer' => 'https://trusted.example/',
        ]);
        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * Returns exactly the documented schema and private response headers.
     */
    public function testAuthorizedRequestReturnsJson(): void
    {
        $this->mockInformationService(true, true);
        $response = $this->get(self::URL, null, ['Authorization' => 'bearer ' . $this->token]);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame(
            ['schema_version' => 2, 'inventory' => array_merge(self::INFORMATION,
                ['database_product' => 'unknown', 'composer_packages' => self::PACKAGES]), 'health_checks' => []],
            json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)
        );
        self::assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
        self::assertStringContainsString('no-store', $response->getHeader('Cache-Control'));
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        self::assertStringNotContainsString($this->token, $response->getBody());
    }

    /**
     * Keeps the authenticated API read-only.
     */
    public function testPostIsRejected(): void
    {
        $this->mockInformationService(false);
        $response = $this->post(self::URL, [], ['Authorization' => 'Bearer ' . $this->token]);
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeader('Allow'));
    }

    /**
     * Displays the same information in read-only CMS fields without an API token.
     */
    public function testBackendUsesTheSameInformation(): void
    {
        Environment::setEnv(ProjectInformationController::TOKEN_ENV, '');
        $this->mockInformationService(true);
        $admin = DeveloperToolsAdmin::create();
        $fields = DeveloperToolsConfig::create()->getCMSFields();
        foreach (self::INFORMATION as $key => $value) {
            $field = $fields->dataFieldByName($key);
            self::assertInstanceOf(ReadonlyField::class, $field);
            self::assertSame($value, $field->dataValue());
        }
        self::assertSame('ADMIN', $admin->config()->get('required_permission_codes'));
        self::assertFalse($admin->canView());
    }

    /**
     * Injects version data and verifies that rejected requests never collect it.
     */
    private function mockInformationService(bool $called, bool $packagesCalled = false): void
    {
        $health = $this->createMock(HealthCheckService::class);
        $health->expects($called ? $this->once() : $this->never())->method('collect')->willReturn([]);
        Injector::inst()->registerService($health, HealthCheckService::class);
        $service = $this->createMock(ProjectInformationService::class);
        $service->expects($called ? $this->once() : $this->never())
            ->method('getInformation')->willReturn(self::INFORMATION);
        $service->expects($packagesCalled ? $this->once() : $this->never())
            ->method('getComposerPackages')->willReturn(self::PACKAGES);
        $service->method('getDatabaseProduct')->willReturn('unknown');
        Injector::inst()->registerService($service, ProjectInformationService::class);
    }
}

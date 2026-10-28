<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Controllers;

use Clesson\Silverstripe\DeveloperTools\Services\ProjectInformationService;
use Clesson\Silverstripe\DeveloperTools\Services\HealthCheckService;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;

/**
 * Exposes project versions only to clients with the configured Bearer token.
 *
 * CMS sessions and request origin headers do not grant access to this endpoint.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Controllers
 */
class ProjectInformationController extends Controller
{
    /** Environment variable containing the shared API token. */
    public const TOKEN_ENV = 'DEVELOPERTOOLS_API_TOKEN';

    /** @config */
    private static array $allowed_actions = [
        'index',
    ];

    /**
     * Returns project information after token authentication, without CMS login.
     */
    public function index(HTTPRequest $request): HTTPResponse
    {
        $token = Environment::getEnv(self::TOKEN_ENV);
        if (!is_string($token) || trim($token) === '') {
            return $this->jsonResponse(['error' => 'Project information endpoint is disabled.'], 503);
        }
        if (!$this->hasValidToken($request, $token)) {
            return $this->jsonResponse(['error' => 'Unauthorized.'], 401)
                ->addHeader('WWW-Authenticate', 'Bearer realm="project-information"');
        }
        if ($request->httpMethod() !== 'GET') {
            return $this->jsonResponse(['error' => 'Method not allowed.'], 405)->addHeader('Allow', 'GET');
        }
        return $this->jsonResponse($this->getProjectInformation());
    }

    /**
     * Combines runtime versions with the installed Composer dependency inventory.
     */
    private function getProjectInformation(): array
    {
        $service = ProjectInformationService::singleton();
        $inventory = array_merge($service->getInformation(), [
            'database_product' => $service->getDatabaseProduct(),
            'composer_packages' => $service->getComposerPackages(),
        ]);
        return ['schema_version' => 2, 'inventory' => $inventory, 'health_checks' => HealthCheckService::singleton()->collect()];
    }

    /**
     * Accepts only a Bearer credential and compares it in constant time.
     */
    private function hasValidToken(HTTPRequest $request, string $token): bool
    {
        $authorization = (string) $request->getHeader('Authorization');
        if (preg_match('/^Bearer ([^\s]+)$/iD', $authorization, $matches) !== 1) {
            return false;
        }
        return hash_equals($token, $matches[1]);
    }

    /**
     * Returns JSON with private, non-cacheable response headers.
     */
    private function jsonResponse(array $data, int $status = 200): HTTPResponse
    {
        return HTTPResponse::create(json_encode($data, JSON_THROW_ON_ERROR), $status)
            ->addHeader('Content-Type', 'application/json; charset=utf-8')
            ->addHeader('Cache-Control', 'private, no-store')
            ->addHeader('X-Content-Type-Options', 'nosniff');
    }
}

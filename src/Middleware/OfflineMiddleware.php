<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Middleware;

use Clesson\Silverstripe\DeveloperTools\Constants\OfflineField as F;
use Clesson\Silverstripe\DeveloperTools\Models\DeveloperToolsConfig;
use Clesson\Silverstripe\DeveloperTools\Services\OfflineMode;
use Clesson\Silverstripe\DeveloperTools\Services\OfflinePageExporter;
use Psr\Log\LoggerInterface;
use SilverStripe\Admin\AdminRootController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Security;

/**
 * Stops dynamic frontend processing before routing while leaving CMS recovery routes reachable.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Middleware
 */
class OfflineMiddleware implements HTTPMiddleware
{
    use Injectable;

    /** Runs after trusted-proxy processing; never delegates a blocked POST or API request. */
    public function process(HTTPRequest $request, callable $delegate): HTTPResponse
    {
        if ($this->isRecoveryPath($request)) {
            return $delegate($request);
        }
        $config = DeveloperToolsConfig::get()->first();
        if (!$config || !$config->getField(F::ACTIVE)) {
            return $delegate($request);
        }
        $mode = OfflineMode::create();
        $response = $mode->isInPeriod($config) && !$mode->isExcepted($config, $request->getIP())
            ? $this->offlineResponse($request)
            : $delegate($request);
        $this->disableCaching($response);
        return $response;
    }

    /** Uses whole path segments, including configured CMS and login routes. */
    private function isRecoveryPath(HTTPRequest $request): bool
    {
        $path = strtolower(trim(rawurldecode($request->getURL()), '/'));
        $paths = [AdminRootController::get_admin_route(), Security::config()->get('login_url'),
            'Security', 'CMSSecurity', '_resources', 'assets'];
        foreach ($paths as $prefix) {
            $prefix = strtolower(trim((string) $prefix, '/'));
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                return true;
            }
        }
        return false;
    }

    /** A missing export remains a visible 503 failure, never an accidental reopening of the site. */
    private function offlineResponse(HTTPRequest $request): HTTPResponse
    {
        $exporter = OfflinePageExporter::create();
        $page = $exporter->publishedPage();
        $html = $page && $page->CanViewType === 'Anyone' ? $exporter->content($page) : null;
        if ($html === null) {
            $html = $this->missingDocument();
        }
        $response = new HTTPResponse($request->httpMethod() === 'HEAD' ? '' : $html, 503);
        $response->addHeader('Content-Type', 'text/html; charset=utf-8');
        $response->addHeader('Retry-After', '300');
        $response->addHeader('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }

    /** Logs configuration damage explicitly while keeping the site closed with a generic error. */
    private function missingDocument(): string
    {
        Injector::inst()->get(LoggerInterface::class)->error('Offline mode is enabled but its published static document is missing.');
        $message = Convert::raw2xml(_t(__CLASS__ . '.UNAVAILABLE', 'Service temporarily unavailable'));
        return '<!doctype html><html><head><meta charset="utf-8"><title>' . $message
            . '</title></head><body><h1>' . $message . '</h1></body></html>';
    }

    /** Prevents scheduled or IP-exempt responses from entering shared caches. */
    private function disableCaching(HTTPResponse $response): void
    {
        $response->addHeader('Cache-Control', 'private, no-store');
        foreach (['Expires', 'ETag', 'Last-Modified', 'Surrogate-Control'] as $header) {
            $response->removeHeader($header);
        }
    }
}

<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Middleware;

use Clesson\Silverstripe\DeveloperTools\Constants\ToastEnvironment;
use Clesson\Silverstripe\DeveloperTools\Constants\ToastField as F;
use Clesson\Silverstripe\DeveloperTools\Helpers\ToastIPRanges;
use Clesson\Silverstripe\DeveloperTools\Models\DeveloperToolsConfig;
use Clesson\Silverstripe\DeveloperTools\Models\ToastMessage;
use Clesson\Silverstripe\DeveloperTools\Services\ToastRenderer;
use SilverStripe\Admin\AdminRootController;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Security;

/**
 * Adds visitor-specific notices to complete frontend pages, outside core caching.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Middleware
 */
class ToastMiddleware implements HTTPMiddleware
{
    use Injectable;

    /** Remembers eligibility before routing mutates the URL; IP matching runs after trusted proxies. */
    public function process(HTTPRequest $request, callable $delegate): HTTPResponse
    {
        $eligible = $this->eligibleRequest($request);
        $response = $delegate($request);
        if (!$eligible || !$this->eligibleResponse($response)) {
            return $response;
        }
        $candidates = $this->candidates();
        if (!$candidates || !$candidates->exists()) {
            return $response;
        }
        $this->disableCaching($response);
        return $this->inject($request, $response, $candidates);
    }

    /** Does not alter form submissions, fragments, CMS routes or machine endpoints. */
    private function eligibleRequest(HTTPRequest $request): bool
    {
        if ($request->httpMethod() !== 'GET' || $request->isAjax()) {
            return false;
        }
        $path = strtolower(trim(rawurldecode($request->getURL()), '/'));
        foreach ($this->excludedPaths() as $excluded) {
            $excluded = strtolower(trim($excluded, '/'));
            if ($excluded !== '' && ($path === $excluded || str_starts_with($path, $excluded . '/'))) {
                return false;
            }
        }
        return true;
    }

    /** Includes the configured CMS base and custom login path, not just default URLs. */
    private function excludedPaths(): array
    {
        return [
            AdminRootController::get_admin_route(),
            (string) Security::config()->get('login_url'),
            'Security', 'dev', 'api', 'graphql', 'developer-tools',
        ];
    }

    /** Requires a complete uncompressed HTML document, never a download or error page. */
    private function eligibleResponse(HTTPResponse $response): bool
    {
        $type = strtolower(trim(explode(';', (string) $response->getHeader('Content-Type'))[0]));
        return $response->getStatusCode() === 200
            && $type === 'text/html'
            && !$response->getHeader('Content-Disposition')
            && !$response->getHeader('Content-Encoding')
            && (bool) preg_match('/<html\b[^>]*>.*<body\b[^>]*>.*<\/body\s*>.*<\/html\s*>/is', $response->getBody());
    }

    /** Reads the existing settings record without creating database records on public GET requests. */
    private function candidates(): ?DataList
    {
        return DeveloperToolsConfig::get()->first()?->ToastMessages()->filter([
            F::ACTIVE => true,
            F::ENVIRONMENT => [ToastEnvironment::ALL, Director::get_environment_type()],
        ]);
    }

    /** Prevents shared caches and conditional revalidation from leaking visitor-specific content. */
    private function disableCaching(HTTPResponse $response): void
    {
        $response->addHeader('Cache-Control', 'private, no-store');
        $response->removeHeader('Expires');
        $response->removeHeader('ETag');
        $response->removeHeader('Last-Modified');
        $response->removeHeader('Surrogate-Control');
    }

    /** Serializes only audience-matching messages and inserts one overlay before the final body close. */
    private function inject(HTTPRequest $request, HTTPResponse $response, DataList $candidates): HTTPResponse
    {
        $messages = [];
        foreach ($this->withinDisplayPeriod($candidates) as $message) {
            if ($this->matchesGroups($message) && ToastIPRanges::matches((string) $message->IPRanges, $request->getIP())) {
                $messages[] = $message;
            }
        }
        $body = $response->getBody();
        if ($messages === [] || str_contains($body, 'id="' . ToastRenderer::OVERLAY_ID . '"')) {
            return $response;
        }
        $html = ToastRenderer::create()->render($messages);
        $offset = strripos($body, '</body');
        $response->setBody(substr($body, 0, $offset) . $html . substr($body, $offset));
        $response->removeHeader('Content-Length');
        return $response;
    }

    /** Empty groups are public; selected groups require an actual membership, including inherited ones. */
    private function matchesGroups(ToastMessage $message): bool
    {
        $groups = $message->Groups();
        return !$groups->exists() || (Security::getCurrentUser()?->inGroups($groups) ?? false);
    }

    /** Filters after disabling caching so cached pages cannot hide future scheduled notices. */
    private function withinDisplayPeriod(DataList $candidates): DataList
    {
        $now = DBDatetime::now()->getValue();
        return $candidates->filterAny([
            F::START_DATE => null,
            F::START_DATE . ':LessThanOrEqual' => $now,
        ])->filterAny([
            F::END_DATE => null,
            F::END_DATE . ':GreaterThan' => $now,
        ]);
    }
}

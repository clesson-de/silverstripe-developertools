<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus;
use DomainException;
use SilverStripe\Core\Injector\Injectable;
use UnexpectedValueException;

/**
 * Assesses a direct HTTP-to-HTTPS redirect independently of the remote inventory.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class MonitoringRedirectCheck
{
    use Injectable;

    /** Reserved central result ID, never accepted from a remote inventory response. */
    public const ID = 'monitoring.http_https_redirect';

    /** Expected network failures remain explicitly unassessable, not successful checks. */
    public function check(string $baseURL): array
    {
        try {
            return $this->assess(MonitoringRedirectClient::singleton()->probe($baseURL));
        } catch (DomainException | UnexpectedValueException $exception) {
            return $this->result(HealthStatus::UNKNOWN, _t(__CLASS__ . '.UNKNOWN',
                'HTTP could not be checked: the destination was rejected or no complete response was received.'));
        }
    }

    /** All answered requests without one unambiguous HTTPS redirect require attention. */
    private function assess(array $response): array
    {
        $redirect = in_array($response['status'], [301, 302, 303, 307, 308], true)
            && !$response['invalid'] && count($response['locations']) === 1
            && $this->validTarget($response['locations'][0]);
        return $redirect
            ? $this->result(HealthStatus::OK, _t(__CLASS__ . '.OK', 'HTTP redirects directly to HTTPS.'))
            : $this->result(HealthStatus::WARNING, _t(__CLASS__ . '.WARNING',
                'HTTP does not redirect directly to a valid HTTPS URL.'));
    }

    /** Allows canonical host/path changes without resolving or fetching the redirect target. */
    private function validTarget(string $url): bool
    {
        $parts = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass'])
            && !preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            && (!isset($parts['port']) || $parts['port'] >= 1);
    }

    /** Shares the existing bounded health report contract and observation timestamp. */
    private function result(string $status, string $message): array
    {
        return HealthReport::singleton()->result(self::ID, _t(__CLASS__ . '.TITLE', 'HTTP to HTTPS redirect'),
            $status, $message);
    }
}

<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\HealthChecks;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus;
use Clesson\Silverstripe\DeveloperTools\Services\ExternalCertificateCheck;
use Clesson\Silverstripe\DeveloperTools\Services\ExternalSecurityHeaderCheck;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReport;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringRedirectCheck;
use SilverStripe\Control\Director;

/**
 * Performs bounded external transport checks for this public base URL.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage HealthChecks
 */
class ExternalTransportHealthCheck implements HealthCheck
{
    public function run(float $deadline): array
    {
        $target = $this->targetURL();
        if ($target === null) {
            return [
                HealthReport::singleton()->result(
                    'external.endpoint',
                    _t(__CLASS__ . '.TITLE', 'External transport checks'),
                    HealthStatus::UNKNOWN,
                    _t(__CLASS__ . '.UNAVAILABLE', 'External checks are not testable for this base URL.'),
                    [
                        'category' => 'security.transport',
                        'source' => HealthReport::SOURCE_EXTERNAL,
                        'state' => HealthReport::STATE_NOT_TESTABLE,
                        'severity' => 'low',
                        'priority' => 'low',
                        'target' => (string) Director::absoluteBaseURL(),
                        'evidence' => 'Public HTTPS target URL could not be derived from configured base URL.',
                        'impact' => 'Transport behaviour cannot be assessed without a valid public destination.',
                        'remediation' => 'Configure a valid public base URL and ensure it resolves publicly.',
                        'limitations' => 'Check is intentionally restricted to validated public targets.',
                    ]
                ),
            ];
        }

        $rows = [MonitoringRedirectCheck::singleton()->check($target)];
        $rows[] = ExternalCertificateCheck::singleton()->check($target, $deadline);
        return array_merge($rows, ExternalSecurityHeaderCheck::singleton()->check($target, $deadline));
    }

    private function targetURL(): ?string
    {
        $base = trim((string) Director::absoluteBaseURL());
        if ($base === '') {
            return null;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $host = (string) $parts['host'];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        $port = isset($parts['port']) && (int) $parts['port'] !== 80 ? ':' . (int) $parts['port'] : '';
        return 'https://' . $host . $port . $path;
    }
}



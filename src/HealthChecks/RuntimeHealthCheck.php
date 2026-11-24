<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\HealthChecks;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus as S;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReport;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;

/**
 * Local observations do not claim to verify external webserver access controls.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage HealthChecks
 */
class RuntimeHealthCheck implements HealthCheck
{
    /** Reports environment, request transport and local public-root separation. */
    public function run(float $deadline): array
    {
        $report = HealthReport::singleton();
        return [
            $report->result('runtime.live', _t(__CLASS__ . '.MODE', 'Live mode'),
                Director::isLive() ? S::OK : S::WARNING,
                _t(__CLASS__ . '.MODE_MESSAGE', 'Production installations should run in live mode.'), [
                    'category' => 'security.runtime',
                    'source' => HealthReport::SOURCE_LOCAL,
                    'target' => 'Environment',
                    'evidence' => Director::isLive() ? 'Director::isLive() returned true.' : 'Director::isLive() returned false.',
                    'impact' => 'Non-live runtime may expose debug information and weaker production defaults.',
                    'remediation' => 'Set runtime environment to live for production deployments.',
                    'limitations' => 'Local configuration signal only; does not verify host-level overrides.',
                ]),
            $this->transport(),
            $this->defaultAdmin(),
        ];
    }

    /** CLI execution cannot observe the browser-facing TLS connection. */
    private function transport(): array
    {
        $status = PHP_SAPI === 'cli' ? S::UNKNOWN : (Director::is_https() ? S::OK : S::ERROR);
        return HealthReport::singleton()->result('runtime.https', _t(__CLASS__ . '.HTTPS', 'HTTPS request'),
            $status, _t(__CLASS__ . '.HTTPS_MESSAGE', 'Local request observation only; external TLS and proxy configuration are not verified.'), [
                'category' => 'security.transport',
                'source' => HealthReport::SOURCE_LOCAL,
                'target' => 'Current request',
                'evidence' => PHP_SAPI === 'cli'
                    ? 'Executed in CLI; request transport is not observable.'
                    : (Director::is_https() ? 'Director::is_https() returned true.' : 'Director::is_https() returned false.'),
                'impact' => 'Insecure transport can expose credentials and session data in transit.',
                'remediation' => 'Enforce HTTPS and verify edge/proxy forwarding configuration.',
                'limitations' => 'Only checks this runtime context, not full edge/CDN behaviour.',
                'state' => PHP_SAPI === 'cli' ? HealthReport::STATE_NOT_TESTABLE : ($status === S::ERROR ? HealthReport::STATE_PROBLEM : HealthReport::STATE_PASSED),
            ]);
    }

    /** Flags default admin credentials in live mode to avoid known shared secrets. */
    private function defaultAdmin(): array
    {
        $username = trim((string) Environment::getEnv('SS_DEFAULT_ADMIN_USERNAME'));
        $password = trim((string) Environment::getEnv('SS_DEFAULT_ADMIN_PASSWORD'));
        $risky = Director::isLive() && $username !== '' && $password !== '';
        return HealthReport::singleton()->result(
            'runtime.default_admin',
            _t(__CLASS__ . '.DEFAULT_ADMIN', 'Default admin credentials'),
            $risky ? S::WARNING : S::OK,
            $risky
                ? _t(__CLASS__ . '.DEFAULT_ADMIN_WARNING', 'Default admin credentials are configured in live mode. Remove SS_DEFAULT_ADMIN_USERNAME and SS_DEFAULT_ADMIN_PASSWORD.')
                : _t(__CLASS__ . '.DEFAULT_ADMIN_OK', 'No default admin credentials are active in live mode.'),
            [
                'category' => 'security.authentication',
                'source' => HealthReport::SOURCE_LOCAL,
                'target' => 'Environment variables SS_DEFAULT_ADMIN_*',
                'evidence' => $risky
                    ? 'Both SS_DEFAULT_ADMIN_USERNAME and SS_DEFAULT_ADMIN_PASSWORD are set in live mode.'
                    : 'No active default admin credentials in live mode were detected.',
                'impact' => 'Shared default credentials increase risk of unauthorized admin access.',
                'remediation' => 'Remove default admin env variables and use managed user provisioning.',
                'limitations' => 'Checks env values only; does not inspect external auth integrations.',
                'severity' => $risky ? 'high' : 'none',
                'priority' => $risky ? 'high' : 'low',
            ]
        );
    }
}

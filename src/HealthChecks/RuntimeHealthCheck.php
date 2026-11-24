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
                _t(__CLASS__ . '.MODE_MESSAGE', 'Production installations should run in live mode.')),
            $this->transport(),
            $this->defaultAdmin(),
        ];
    }

    /** CLI execution cannot observe the browser-facing TLS connection. */
    private function transport(): array
    {
        $status = PHP_SAPI === 'cli' ? S::UNKNOWN : (Director::is_https() ? S::OK : S::ERROR);
        return HealthReport::singleton()->result('runtime.https', _t(__CLASS__ . '.HTTPS', 'HTTPS request'),
            $status, _t(__CLASS__ . '.HTTPS_MESSAGE', 'Local request observation only; external TLS and proxy configuration are not verified.'));
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
                : _t(__CLASS__ . '.DEFAULT_ADMIN_OK', 'No default admin credentials are active in live mode.')
        );
    }
}

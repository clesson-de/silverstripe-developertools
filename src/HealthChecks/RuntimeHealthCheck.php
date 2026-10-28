<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\HealthChecks;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus as S;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReport;
use SilverStripe\Control\Director;

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
            $this->webroot(),
        ];
    }

    /** CLI execution cannot observe the browser-facing TLS connection. */
    private function transport(): array
    {
        $status = PHP_SAPI === 'cli' ? S::UNKNOWN : (Director::is_https() ? S::OK : S::ERROR);
        return HealthReport::singleton()->result('runtime.https', _t(__CLASS__ . '.HTTPS', 'HTTPS request'),
            $status, _t(__CLASS__ . '.HTTPS_MESSAGE', 'Local request observation only; external TLS and proxy configuration are not verified.'));
    }

    /** Detects locally visible sensitive paths without reading or publishing their contents. */
    private function webroot(): array
    {
        $public = Director::publicFolder();
        if (!is_dir($public)) {
            return HealthReport::singleton()->result('runtime.webroot', _t(__CLASS__ . '.WEBROOT', 'Public webroot separation'),
                S::UNKNOWN, _t(__CLASS__ . '.WEBROOT_MISSING', 'The configured public directory is not available.'));
        }
        return HealthReport::singleton()->result('runtime.webroot', _t(__CLASS__ . '.WEBROOT', 'Public webroot separation'),
            $this->webrootStatus($public), _t(__CLASS__ . '.WEBROOT_MESSAGE', 'Checks local webroot separation and sensitive paths, not HTTP access protection.'));
    }

    /** Flags sensitive paths locally without claiming external accessibility. */
    private function webrootStatus(string $public): string
    {
        $status = realpath($public) === realpath(Director::baseFolder()) ? S::ERROR : S::OK;
        foreach (['.env', '.git', 'composer.json', 'composer.lock', 'vendor', 'app/src'] as $path) {
            if (file_exists($public . '/' . $path) || is_link($public . '/' . $path)) {
                $status = S::ERROR;
            }
        }
        return $status;
    }
}

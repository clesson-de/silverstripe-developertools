<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\HealthChecks;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus as S;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReport;
use DirectoryIterator;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Injector\Injector;
use Throwable;

/**
 * Verifies local runtime configuration required for operations and safe public surface.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage HealthChecks
 */
class ConfigurationHealthCheck implements HealthCheck
{
    /** Performs cheap local checks only and never reads file contents. */
    public function run(float $deadline): array
    {
        $report = HealthReport::singleton();
        return [
            $this->logger($report),
            $this->sender($report),
            $this->publicPhpFiles($report),
            $this->publicFile($report, 'config.sitemap', 'sitemap.xml',
                _t(__CLASS__ . '.SITEMAP', 'Sitemap file'),
                _t(__CLASS__ . '.SITEMAP_OK', 'sitemap.xml exists in the public root.'),
                _t(__CLASS__ . '.SITEMAP_MISSING', 'sitemap.xml is missing in the public root.')),
            $this->publicFile($report, 'config.robots', 'robots.txt',
                _t(__CLASS__ . '.ROBOTS', 'Robots file'),
                _t(__CLASS__ . '.ROBOTS_OK', 'robots.txt exists in the public root.'),
                _t(__CLASS__ . '.ROBOTS_MISSING', 'robots.txt is missing in the public root.')),
        ];
    }

    /** Ensures the PSR logger service can be resolved via the injector. */
    private function logger(HealthReport $report): array
    {
        try {
            $logger = Injector::inst()->get(LoggerInterface::class);
            $status = $logger instanceof LoggerInterface ? S::OK : S::ERROR;
        } catch (Throwable) {
            $status = S::ERROR;
        }
        return $report->result(
            'config.logger',
            _t(__CLASS__ . '.LOGGER', 'Logger configuration'),
            $status,
            $status === S::OK
                ? _t(__CLASS__ . '.LOGGER_OK', 'A PSR logger service is configured.')
                : _t(__CLASS__ . '.LOGGER_MISSING', 'No PSR logger service is configured.'),
            [
                'category' => 'operations.logging',
                'source' => HealthReport::SOURCE_LOCAL,
                'target' => 'Injector: ' . LoggerInterface::class,
                'evidence' => $status === S::OK ? 'Logger service resolved successfully.' : 'Logger service could not be resolved.',
                'impact' => 'Missing logging reduces incident visibility and troubleshooting quality.',
                'remediation' => 'Register a PSR logger service in the injector configuration.',
                'limitations' => 'Resolves service only; does not validate downstream log transport.',
                'severity' => $status === S::OK ? 'none' : 'medium',
            ]
        );
    }

    /** Confirms that a dedicated default sender address is configured in Email.admin_email. */
    private function sender(HealthReport $report): array
    {
        $configured = count($this->configuredSenderAddresses()) > 0;
        return $report->result(
            'config.sender',
            _t(__CLASS__ . '.SENDER', 'Default sender email'),
            $configured ? S::OK : S::WARNING,
            $configured
                ? _t(__CLASS__ . '.SENDER_OK', 'Email admin sender address is configured.')
                : _t(__CLASS__ . '.SENDER_MISSING', 'Email admin sender address is not configured (Email.admin_email).'),
            [
                'category' => 'operations.mail',
                'source' => HealthReport::SOURCE_LOCAL,
                'target' => 'Config: Email.admin_email',
                'evidence' => $configured ? 'At least one valid sender address configured.' : 'No valid admin sender address configured.',
                'impact' => 'Operational emails may fail or use unintended fallback senders.',
                'remediation' => 'Configure a valid sender address via Email.admin_email.',
                'limitations' => 'Does not validate DNS/SPF/DMARC deliverability.',
                'severity' => $configured ? 'none' : 'medium',
            ]
        );
    }

    /** Flags public-root PHP scripts beyond index.php to reduce accidental code exposure. */
    private function publicPhpFiles(HealthReport $report): array
    {
        $public = Director::publicFolder();
        if (!is_dir($public) || !is_readable($public)) {
            return $report->result(
                'config.public_php',
                _t(__CLASS__ . '.PUBLIC_PHP', 'Public PHP files'),
                S::UNKNOWN,
                _t(__CLASS__ . '.PUBLIC_PHP_UNAVAILABLE', 'The configured public directory is not available for inspection.'),
                [
                    'category' => 'security.exposure',
                    'source' => HealthReport::SOURCE_LOCAL,
                    'target' => 'Public directory',
                    'evidence' => 'Public directory is missing or not readable.',
                    'impact' => 'Unable to assess webroot script exposure risk.',
                    'remediation' => 'Verify public directory path and read permissions.',
                    'limitations' => 'No remote reachability verification is performed.',
                    'state' => HealthReport::STATE_NOT_TESTABLE,
                ]
            );
        }
        $phpFiles = [];
        foreach (new DirectoryIterator($public) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $name = $entry->getFilename();
            if (strtolower($name) === 'index.php') {
                continue;
            }
            if (($entry->isFile() || $entry->isLink()) && preg_match('/\.php$/i', $name) === 1) {
                $phpFiles[] = $name;
            }
        }
        sort($phpFiles, SORT_NATURAL | SORT_FLAG_CASE);
        if (!$phpFiles) {
            return $report->result(
                'config.public_php',
                _t(__CLASS__ . '.PUBLIC_PHP', 'Public PHP files'),
                S::OK,
                _t(__CLASS__ . '.PUBLIC_PHP_OK', 'No PHP files are present in the public root except index.php.'),
                [
                    'category' => 'security.exposure',
                    'source' => HealthReport::SOURCE_LOCAL,
                    'target' => $public,
                    'evidence' => 'No additional .php files detected in public root.',
                    'impact' => 'Reduces risk of unintended executable entry points in webroot.',
                    'remediation' => 'Keep executable scripts outside the public root except index.php.',
                    'limitations' => 'Does not prove unreachable aliases or external server mappings.',
                ]
            );
        }
        $sample = implode(', ', array_slice($phpFiles, 0, 5));
        if (count($phpFiles) > 5) {
            $sample .= ', ...';
        }
        return $report->result(
            'config.public_php',
            _t(__CLASS__ . '.PUBLIC_PHP', 'Public PHP files'),
            S::ERROR,
            _t(__CLASS__ . '.PUBLIC_PHP_FOUND', 'Unexpected PHP files in public root: {files}', ['files' => $sample]),
            [
                'category' => 'security.exposure',
                'source' => HealthReport::SOURCE_LOCAL,
                'target' => $public,
                'evidence' => 'Detected files: ' . $sample,
                'impact' => 'Unexpected public PHP files may expose unreviewed executable endpoints.',
                'remediation' => 'Remove or relocate these files outside the public root.',
                'limitations' => 'Presence check only; no file content analysis is performed.',
                'severity' => 'high',
                'priority' => 'high',
                'state' => HealthReport::STATE_PROBLEM,
            ]
        );
    }

    /** Verifies static safety files in public root without reading their contents. */
    private function publicFile(
        HealthReport $report,
        string $id,
        string $filename,
        string $title,
        string $okMessage,
        string $missingMessage
    ): array {
        $public = Director::publicFolder();
        if (!is_dir($public) || !is_readable($public)) {
            return $report->result(
                $id,
                $title,
                S::UNKNOWN,
                _t(__CLASS__ . '.PUBLIC_PHP_UNAVAILABLE', 'The configured public directory is not available for inspection.'),
                [
                    'category' => 'operations.static_assets',
                    'source' => HealthReport::SOURCE_LOCAL,
                    'target' => 'Public directory',
                    'evidence' => 'Public directory is missing or not readable.',
                    'impact' => 'Unable to verify presence of expected public control files.',
                    'remediation' => 'Verify public directory path and read permissions.',
                    'limitations' => 'No remote HTTP fetch is attempted in this check.',
                    'state' => HealthReport::STATE_NOT_TESTABLE,
                ]
            );
        }
        $path = $public . DIRECTORY_SEPARATOR . $filename;
        $exists = is_file($path) || is_link($path);
        return $report->result($id, $title, $exists ? S::OK : S::WARNING, $exists ? $okMessage : $missingMessage, [
            'category' => 'operations.static_assets',
            'source' => HealthReport::SOURCE_LOCAL,
            'target' => $path,
            'evidence' => $exists ? ($filename . ' found in public root.') : ($filename . ' not found in public root.'),
            'impact' => $exists
                ? 'Expected control file is available for crawlers/consumers.'
                : 'Missing control file can reduce indexing or operational transparency.',
            'remediation' => 'Provide and deploy the file when applicable for this project.',
            'limitations' => 'Checks local filesystem presence only, not effective external delivery.',
        ]);
    }

    /** @return list<string> */
    private function configuredSenderAddresses(): array
    {
        $raw = Email::config()->get('admin_email');
        $addresses = [];
        if (is_string($raw)) {
            $value = trim($raw);
            if ($value !== '' && Email::is_valid_address($value)) {
                $addresses[] = $value;
            }
            return $addresses;
        }
        if (!is_array($raw)) {
            return [];
        }
        foreach ($raw as $key => $value) {
            $candidate = is_string($key) && Email::is_valid_address($key)
                ? $key
                : (is_string($value) ? trim($value) : '');
            if ($candidate !== '' && Email::is_valid_address($candidate)) {
                $addresses[] = $candidate;
            }
        }
        return array_values(array_unique($addresses));
    }
}



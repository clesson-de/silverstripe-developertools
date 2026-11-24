<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\HealthChecks;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReport;
use Clesson\Silverstripe\DeveloperTools\Services\MailDnsCheck;
use SilverStripe\Control\Email\Email;

/**
 * Checks SPF and DMARC for configured sender domains.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage HealthChecks
 */
class MailDnsHealthCheck implements HealthCheck
{
    public function run(float $deadline): array
    {
        $domains = $this->senderDomains();
        if ($domains === []) {
            return [
                HealthReport::singleton()->result(
                    'mail.dns.config',
                    _t(__CLASS__ . '.TITLE', 'Mail DNS policy'),
                    HealthStatus::UNKNOWN,
                    _t(__CLASS__ . '.UNAVAILABLE', 'No configured sender domain is available for SPF/DMARC checks.'),
                    [
                        'category' => 'security.mail_dns',
                        'source' => HealthReport::SOURCE_LOCAL,
                        'state' => HealthReport::STATE_NOT_APPLICABLE,
                        'severity' => 'low',
                        'priority' => 'low',
                        'target' => 'Email.admin_email',
                        'evidence' => 'No valid sender domain could be derived from Email.admin_email configuration.',
                        'impact' => 'Mail DNS checks cannot be scoped without explicit sender domains.',
                        'remediation' => 'Configure explicit sender domains first, then run SPF/DMARC checks.',
                        'limitations' => 'Domain inference from website host is intentionally avoided.',
                    ]
                ),
            ];
        }

        $rows = [];
        foreach ($domains as $domain) {
            array_push($rows, ...MailDnsCheck::singleton()->checkDomain($domain, $deadline));
            if (microtime(true) >= $deadline) {
                break;
            }
        }
        return $rows;
    }

    /** @return list<string> */
    private function senderDomains(): array
    {
        $raw = Email::config()->get('admin_email');
        $values = [];
        if (is_string($raw)) {
            $values[] = trim($raw);
        } elseif (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (is_string($key) && Email::is_valid_address($key)) {
                    $values[] = trim($key);
                } elseif (is_string($value)) {
                    $values[] = trim($value);
                }
            }
        }

        $domains = [];
        foreach ($values as $address) {
            if (!Email::is_valid_address($address)) {
                continue;
            }
            $domain = strtolower((string) substr(strrchr($address, '@') ?: '', 1));
            if ($domain !== '' && preg_match('/\A[a-z0-9.-]{1,253}\z/D', $domain) === 1) {
                $domains[] = $domain;
            }
        }
        $domains = array_values(array_unique($domains));
        sort($domains, SORT_NATURAL | SORT_FLAG_CASE);
        return $domains;
    }
}


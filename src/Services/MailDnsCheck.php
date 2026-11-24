<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;

/**
 * Performs bounded SPF/DMARC DNS checks for configured sender domains.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class MailDnsCheck
{
    use Injectable;

    /** @return list<array<string, string>> */
    public function checkDomain(string $domain, float $deadline): array
    {
        return array_merge($this->spf($domain, $deadline), $this->dmarc($domain, $deadline));
    }

    /** @return list<array<string, string>> */
    private function spf(string $domain, float $deadline): array
    {
        if (microtime(true) >= $deadline) {
            return [$this->result(
                $this->id('mail.spf.', $domain),
                _t(__CLASS__ . '.SPF_TITLE', 'SPF policy') . ' (' . $domain . ')',
                HealthStatus::UNKNOWN,
                _t(__CLASS__ . '.BUDGET', 'DNS check skipped because the execution budget expired.'),
                [
                    'state' => HealthReport::STATE_NOT_TESTABLE,
                    'target' => $domain,
                    'evidence' => 'Execution budget expired before DNS lookup.',
                ]
            )];
        }

        try {
            $txtRecords = $this->txtRecords($domain);
        } catch (RuntimeException) {
            return [$this->result(
                $this->id('mail.spf.', $domain),
                _t(__CLASS__ . '.SPF_TITLE', 'SPF policy') . ' (' . $domain . ')',
                HealthStatus::UNKNOWN,
                _t(__CLASS__ . '.DNS_FAILED', 'DNS lookup failed for this domain.'),
                [
                    'state' => HealthReport::STATE_NOT_TESTABLE,
                    'target' => $domain,
                    'evidence' => 'TXT DNS lookup returned no reliable result.',
                ]
            )];
        }

        $spf = array_values(array_filter($txtRecords, static fn(string $record): bool => str_starts_with(strtolower(trim($record)), 'v=spf1')));
        if ($spf === []) {
            return [
                $this->result(
                $this->id('mail.spf.', $domain),
                _t(__CLASS__ . '.SPF_TITLE', 'SPF policy') . ' (' . $domain . ')',
                HealthStatus::WARNING,
                _t(__CLASS__ . '.SPF_MISSING', 'No SPF record was found for this sender domain.'),
                [
                    'state' => HealthReport::STATE_RECOMMENDATION,
                    'severity' => 'medium',
                    'priority' => 'medium',
                    'target' => $domain,
                    'evidence' => 'No TXT record with v=spf1 prefix detected.',
                    'remediation' => 'Publish exactly one SPF record for this sending domain.',
                ]
                ),
                $this->spfIssue($domain, 'missing', HealthStatus::WARNING, 'No SPF record with v=spf1 was detected.',
                    'No TXT record with SPF version marker was found.',
                    'Publish one SPF TXT record for this sender domain.'),
            ];
        }

        if (count($spf) > 1) {
            return [
                $this->result(
                $this->id('mail.spf.', $domain),
                _t(__CLASS__ . '.SPF_TITLE', 'SPF policy') . ' (' . $domain . ')',
                HealthStatus::ERROR,
                _t(__CLASS__ . '.SPF_MULTI', 'Multiple SPF records were found and make policy evaluation ambiguous.'),
                [
                    'state' => HealthReport::STATE_PROBLEM,
                    'severity' => 'high',
                    'priority' => 'high',
                    'target' => $domain,
                    'evidence' => 'Detected more than one TXT record starting with v=spf1.',
                    'remediation' => 'Merge policy terms into one single SPF record.',
                ]
                ),
                $this->spfIssue($domain, 'multiple_records', HealthStatus::ERROR, 'Multiple SPF records detected.',
                    'More than one TXT record starts with v=spf1.',
                    'Keep exactly one SPF TXT record.', 'high', 'high', HealthReport::STATE_PROBLEM),
            ];
        }

        $record = trim($spf[0]);
        if (preg_match('/(?:^|\s)\+all(?:\s|$)/i', $record) === 1) {
            return [
                $this->result(
                $this->id('mail.spf.', $domain),
                _t(__CLASS__ . '.SPF_TITLE', 'SPF policy') . ' (' . $domain . ')',
                HealthStatus::ERROR,
                _t(__CLASS__ . '.SPF_PLUS_ALL', 'SPF contains +all and effectively allows spoofing.'),
                [
                    'state' => HealthReport::STATE_PROBLEM,
                    'severity' => 'critical',
                    'priority' => 'critical',
                    'target' => $domain,
                    'evidence' => 'SPF term +all detected.',
                    'remediation' => 'Replace +all with a restrictive policy after validating legitimate senders.',
                ]
                ),
                $this->spfIssue($domain, 'plus_all', HealthStatus::ERROR, 'SPF contains +all.',
                    'SPF term +all allows all sending hosts.',
                    'Replace +all with a restrictive policy.', 'critical', 'critical', HealthReport::STATE_PROBLEM),
            ];
        }

        $complexity = $this->spfComplexityHints($record, $domain);
        if ($complexity['issues'] !== []) {
            $rows = [
                $this->result(
                $this->id('mail.spf.', $domain),
                _t(__CLASS__ . '.SPF_TITLE', 'SPF policy') . ' (' . $domain . ')',
                HealthStatus::WARNING,
                _t(__CLASS__ . '.SPF_COMPLEXITY', 'SPF includes potentially risky include/redirect complexity and should be reviewed.'),
                [
                    'state' => HealthReport::STATE_RECOMMENDATION,
                    'severity' => 'medium',
                    'priority' => 'medium',
                    'target' => $domain,
                    'evidence' => implode(' ', $complexity['issues']),
                    'remediation' => 'Review include/redirect chains and avoid recursive or overly complex SPF structures.',
                    'limitations' => 'String-level SPF analysis only; no full recursive DNS evaluation is performed.',
                ]
                ),
            ];
            foreach ($complexity['issues'] as $index => $issue) {
                $rows[] = $this->spfIssue(
                    $domain,
                    'complexity_' . (string) $index,
                    HealthStatus::WARNING,
                    'SPF complexity hint',
                    $issue,
                    'Review include/redirect terms and simplify SPF structure when possible.'
                );
            }
            return $rows;
        }

        $lookupHint = $this->spfLookupHint($record);
        if ($lookupHint > 10) {
            return [
                $this->result(
                $this->id('mail.spf.', $domain),
                _t(__CLASS__ . '.SPF_TITLE', 'SPF policy') . ' (' . $domain . ')',
                HealthStatus::WARNING,
                _t(__CLASS__ . '.SPF_LOOKUPS', 'SPF appears to exceed recommended DNS lookup complexity.'),
                [
                    'state' => HealthReport::STATE_RECOMMENDATION,
                    'severity' => 'medium',
                    'priority' => 'medium',
                    'target' => $domain,
                    'evidence' => sprintf('Heuristic SPF DNS-triggering mechanism count: %d.', $lookupHint),
                    'remediation' => 'Reduce includes/mechanisms and validate effective DNS resolution behaviour.',
                    'limitations' => 'Heuristic count only; full recursive SPF evaluation is not performed.',
                ]
                ),
                $this->spfIssue($domain, 'lookup_budget', HealthStatus::WARNING, 'SPF lookup complexity appears high.',
                    sprintf('Heuristic DNS-triggering mechanism count is %d (>10).', $lookupHint),
                    'Reduce DNS-triggering mechanisms and verify recursive SPF evaluation paths.'),
            ];
        }

        if (preg_match('/(?:^|\s)p\=none(?:\s|$)/i', $record) === 1) {
            // SPF has no p= tag; intentionally no-op. Keep compatibility with generic parser assumptions.
        }

        return [
            $this->result(
                $this->id('mail.spf.', $domain),
                _t(__CLASS__ . '.SPF_TITLE', 'SPF policy') . ' (' . $domain . ')',
                HealthStatus::OK,
                _t(__CLASS__ . '.SPF_OK', 'SPF record is present and has no immediately critical pattern.'),
                [
                    'state' => HealthReport::STATE_PASSED,
                    'target' => $domain,
                    'evidence' => sprintf('One SPF record detected without +all. Heuristic DNS-triggering mechanism count: %d.', $lookupHint),
                ]
            ),
        ];
    }

    /** @return list<array<string, string>> */
    private function dmarc(string $domain, float $deadline): array
    {
        if (microtime(true) >= $deadline) {
            return [$this->result(
                $this->id('mail.dmarc.', $domain),
                _t(__CLASS__ . '.DMARC_TITLE', 'DMARC policy') . ' (' . $domain . ')',
                HealthStatus::UNKNOWN,
                _t(__CLASS__ . '.BUDGET', 'DNS check skipped because the execution budget expired.'),
                [
                    'state' => HealthReport::STATE_NOT_TESTABLE,
                    'target' => '_dmarc.' . $domain,
                    'evidence' => 'Execution budget expired before DNS lookup.',
                ]
            )];
        }

        $name = '_dmarc.' . $domain;
        try {
            $txtRecords = $this->txtRecords($name);
        } catch (RuntimeException) {
            return [$this->result(
                $this->id('mail.dmarc.', $domain),
                _t(__CLASS__ . '.DMARC_TITLE', 'DMARC policy') . ' (' . $domain . ')',
                HealthStatus::UNKNOWN,
                _t(__CLASS__ . '.DNS_FAILED', 'DNS lookup failed for this domain.'),
                [
                    'state' => HealthReport::STATE_NOT_TESTABLE,
                    'target' => $name,
                    'evidence' => 'TXT DNS lookup returned no reliable result.',
                ]
            )];
        }

        $dmarc = array_values(array_filter($txtRecords, static fn(string $record): bool => str_starts_with(strtolower(trim($record)), 'v=dmarc1')));
        if ($dmarc === []) {
            return [
                $this->result(
                $this->id('mail.dmarc.', $domain),
                _t(__CLASS__ . '.DMARC_TITLE', 'DMARC policy') . ' (' . $domain . ')',
                HealthStatus::WARNING,
                _t(__CLASS__ . '.DMARC_MISSING', 'No DMARC record was found for this sender domain.'),
                [
                    'state' => HealthReport::STATE_RECOMMENDATION,
                    'severity' => 'medium',
                    'priority' => 'medium',
                    'target' => $name,
                    'evidence' => 'No TXT record with v=DMARC1 prefix detected.',
                    'remediation' => 'Publish a DMARC record and review policy rollout by monitoring alignment results.',
                ]
                ),
                $this->dmarcIssue($domain, 'missing', HealthStatus::WARNING, 'No DMARC record with v=DMARC1 was detected.',
                    'No TXT record with DMARC version marker was found.',
                    'Publish one DMARC TXT record for this sender domain.'),
            ];
        }

        if (count($dmarc) > 1) {
            return [
                $this->result(
                $this->id('mail.dmarc.', $domain),
                _t(__CLASS__ . '.DMARC_TITLE', 'DMARC policy') . ' (' . $domain . ')',
                HealthStatus::ERROR,
                _t(__CLASS__ . '.DMARC_MULTI', 'Multiple DMARC records were found and policy evaluation is ambiguous.'),
                [
                    'state' => HealthReport::STATE_PROBLEM,
                    'severity' => 'high',
                    'priority' => 'high',
                    'target' => $name,
                    'evidence' => 'Detected more than one TXT record starting with v=DMARC1.',
                    'remediation' => 'Keep a single DMARC TXT record for the domain.',
                ]
                ),
                $this->dmarcIssue($domain, 'multiple_records', HealthStatus::ERROR, 'Multiple DMARC records detected.',
                    'More than one TXT record starts with v=DMARC1.',
                    'Keep exactly one DMARC TXT record.', 'high', 'high', HealthReport::STATE_PROBLEM),
            ];
        }

        $record = strtolower(trim($dmarc[0]));
        $policy = $this->dmarcTag($record, 'p');
        if ($policy === '') {
            return [
                $this->result(
                $this->id('mail.dmarc.', $domain),
                _t(__CLASS__ . '.DMARC_TITLE', 'DMARC policy') . ' (' . $domain . ')',
                HealthStatus::ERROR,
                _t(__CLASS__ . '.DMARC_INVALID', 'DMARC record is missing a valid p= policy tag.'),
                [
                    'state' => HealthReport::STATE_PROBLEM,
                    'severity' => 'high',
                    'priority' => 'high',
                    'target' => $name,
                    'evidence' => 'DMARC tag p is absent or malformed.',
                    'remediation' => 'Set a valid p=none|quarantine|reject policy.',
                ]
                ),
                $this->dmarcIssue($domain, 'invalid_policy', HealthStatus::ERROR, 'DMARC p= policy is missing or invalid.',
                    'DMARC record has no valid p= policy tag.',
                    'Set p=none, p=quarantine or p=reject.', 'high', 'high', HealthReport::STATE_PROBLEM),
            ];
        }

        if ($policy === 'none') {
            return [
                $this->result(
                $this->id('mail.dmarc.', $domain),
                _t(__CLASS__ . '.DMARC_TITLE', 'DMARC policy') . ' (' . $domain . ')',
                HealthStatus::WARNING,
                _t(__CLASS__ . '.DMARC_NONE', 'DMARC policy p=none is monitoring only and does not reject spoofed mail.'),
                [
                    'state' => HealthReport::STATE_RECOMMENDATION,
                    'severity' => 'medium',
                    'priority' => 'medium',
                    'target' => $name,
                    'evidence' => 'DMARC policy tag p=none detected.',
                    'remediation' => 'After validating legitimate senders and alignment, consider stronger DMARC policy.',
                    'limitations' => 'Policy hardening should follow monitored traffic analysis, not blanket escalation.',
                ]
                ),
                $this->dmarcIssue($domain, 'policy_none', HealthStatus::WARNING, 'DMARC policy is set to p=none (monitoring only).',
                    'DMARC policy p=none does not enforce reject/quarantine for spoofed mail.',
                    'After sender alignment review, consider p=quarantine or p=reject.'),
            ];
        }

        return [
            $this->result(
                $this->id('mail.dmarc.', $domain),
                _t(__CLASS__ . '.DMARC_TITLE', 'DMARC policy') . ' (' . $domain . ')',
                HealthStatus::OK,
                _t(__CLASS__ . '.DMARC_OK', 'DMARC record is present with an enforcing policy.'),
                [
                    'state' => HealthReport::STATE_PASSED,
                    'target' => $name,
                    'evidence' => 'DMARC policy tag present with value ' . $policy . '.',
                ]
            ),
        ];
    }

    /** @return list<string> */
    protected function txtRecords(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);
        if ($records === false) {
            throw new RuntimeException('DNS lookup failed.');
        }
        $result = [];
        foreach ($records as $record) {
            if (isset($record['txt']) && is_string($record['txt'])) {
                $result[] = trim($record['txt']);
            }
        }
        return $result;
    }

    private function spfLookupHint(string $record): int
    {
        preg_match_all('/(?:^|\s)(?:include:|a(?::|\s|$)|mx(?::|\s|$)|ptr(?::|\s|$)|exists:|redirect=)/i', strtolower($record), $matches);
        return count($matches[0]);
    }

    /** @return array{issues: list<string>, includes: int, redirects: int} */
    private function spfComplexityHints(string $record, string $domain): array
    {
        $tokens = preg_split('/\s+/', strtolower(trim($record))) ?: [];
        $issues = [];
        $includes = 0;
        $redirects = 0;
        foreach ($tokens as $token) {
            if (str_starts_with($token, 'include:')) {
                $includes++;
                $target = trim(substr($token, 8));
                if ($target !== '' && $target === strtolower($domain)) {
                    $issues[] = 'SPF include points to the same domain and may recurse.';
                }
            }
            if (str_starts_with($token, 'redirect=')) {
                $redirects++;
                $target = trim(substr($token, 9));
                if ($target !== '' && $target === strtolower($domain)) {
                    $issues[] = 'SPF redirect points to the same domain and may recurse.';
                }
            }
        }
        if ($redirects > 1) {
            $issues[] = 'SPF contains multiple redirect= terms.';
        }
        if ($redirects >= 1 && $includes >= 4) {
            $issues[] = 'SPF combines redirect= with several include mechanisms.';
        }
        return ['issues' => array_values(array_unique($issues)), 'includes' => $includes, 'redirects' => $redirects];
    }

    private function dmarcTag(string $record, string $tag): string
    {
        foreach (explode(';', $record) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2 && trim($pair[0]) === $tag) {
                return trim($pair[1]);
            }
        }
        return '';
    }

    private function id(string $prefix, string $domain): string
    {
        return $prefix . substr(hash('sha256', strtolower($domain)), 0, 12);
    }

    private function spfIssue(
        string $domain,
        string $type,
        string $status,
        string $message,
        string $evidence,
        string $remediation,
        string $severity = 'medium',
        string $priority = 'medium',
        string $state = HealthReport::STATE_RECOMMENDATION
    ): array {
        return $this->result(
            $this->id('mail.spf.issue.' . $type . '.', $domain),
            _t(__CLASS__ . '.SPF_DETAIL', 'SPF detail') . ' (' . $domain . ')',
            $status,
            $message,
            [
                'state' => $state,
                'severity' => $severity,
                'priority' => $priority,
                'target' => $domain,
                'evidence' => $evidence,
                'remediation' => $remediation,
            ]
        );
    }

    private function dmarcIssue(
        string $domain,
        string $type,
        string $status,
        string $message,
        string $evidence,
        string $remediation,
        string $severity = 'medium',
        string $priority = 'medium',
        string $state = HealthReport::STATE_RECOMMENDATION
    ): array {
        return $this->result(
            $this->id('mail.dmarc.issue.' . $type . '.', $domain),
            _t(__CLASS__ . '.DMARC_DETAIL', 'DMARC detail') . ' (' . $domain . ')',
            $status,
            $message,
            [
                'state' => $state,
                'severity' => $severity,
                'priority' => $priority,
                'target' => '_dmarc.' . $domain,
                'evidence' => $evidence,
                'remediation' => $remediation,
            ]
        );
    }

    private function result(string $id, string $title, string $status, string $message, array $meta): array
    {
        return HealthReport::singleton()->result($id, $title, $status, $message, $meta + [
            'category' => 'security.mail_dns',
            'source' => HealthReport::SOURCE_EXTERNAL,
            'severity' => $status === HealthStatus::ERROR ? 'high' : ($status === HealthStatus::WARNING ? 'medium' : ($status === HealthStatus::UNKNOWN ? 'low' : 'none')),
            'priority' => $status === HealthStatus::ERROR ? 'high' : ($status === HealthStatus::WARNING ? 'medium' : 'low'),
            'impact' => 'Weak sender DNS policy can reduce spoofing resistance and mail trust.',
            'remediation' => 'Align sender domains, SPF and DMARC policies with observed legitimate mail flows.',
            'limitations' => 'DNS-only analysis; alignment and DKIM effectiveness require real sending validation.',
            'reference' => 'https://support.google.com/a/answer/81126',
        ]);
    }
}


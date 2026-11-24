<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus;
use Clesson\Silverstripe\DeveloperTools\Helpers\MonitoringURL;
use DomainException;
use SilverStripe\Core\Injector\Injectable;
use UnexpectedValueException;

/**
 * Performs bounded external checks for security headers and session cookies.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class ExternalSecurityHeaderCheck
{
    use Injectable;

    public const ID_HEADERS = 'external.security_headers';
    public const ID_COOKIES = 'external.session_cookies';

    private const CONNECT_TIMEOUT = 3;
    private const TIMEOUT = 5;
    private const MAX_HEADER_BYTES = 32768;

    /** @return list<array<string, string>> */
    public function check(string $baseURL, float $deadline): array
    {
        if (microtime(true) >= $deadline) {
            return [$this->notTestable('Execution budget expired before external header probe.')];
        }

        try {
            $validator = MonitoringURL::singleton();
            $url = $validator->normalize($baseURL);
            $host = (string) parse_url($url, PHP_URL_HOST);
            $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
            $addresses = $validator->publicAddresses($host);
        } catch (DomainException) {
            return [$this->notTestable('Destination was rejected by public URL validation.')];
        }

        $remaining = max(1, (int) min(self::TIMEOUT, floor($deadline - microtime(true))));
        try {
            $probe = $this->probe($url, $host, $port, $addresses[0], $remaining);
        } catch (UnexpectedValueException) {
            return [$this->notTestable('Header probe did not return complete response headers.')];
        }

        return [
            $this->headersResult($probe['headers']),
            $this->cookiesResult($probe['cookies']),
        ];
    }

    /** @return array{headers: array<string, string>, cookies: list<string>} */
    protected function probe(string $url, string $host, int $port, string $address, int $timeout): array
    {
        $handle = curl_init();
        if (!$handle instanceof \CurlHandle) {
            throw new UnexpectedValueException('Header probe could not be initialized.');
        }
        $result = ['headers' => [], 'cookies' => [], 'complete' => false];
        $bytes = 0;
        curl_setopt_array($handle, $this->options($url, $host, $port, $address, $timeout));
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, $this->headerReader($result, $bytes));
        curl_exec($handle);
        if (!$result['complete']) {
            throw new UnexpectedValueException('Incomplete headers.');
        }
        return ['headers' => $result['headers'], 'cookies' => $result['cookies']];
    }

    protected function options(string $url, string $host, int $port, string $address, int $timeout): array
    {
        $destination = str_contains($address, ':') ? '[' . $address . ']' : $address;
        return [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,*/*;q=0.8'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_RESOLVE => [trim($host, '[]') . ':' . $port . ':' . $destination],
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static fn(\CurlHandle $curl, string $chunk): int => 0,
        ];
    }

    /** @param array<string, mixed> $result */
    private function headerReader(array &$result, int &$bytes): \Closure
    {
        return static function (\CurlHandle $curl, string $line) use (&$result, &$bytes): int {
            $bytes += strlen($line);
            if ($bytes > self::MAX_HEADER_BYTES) {
                return 0;
            }
            if (preg_match('~^HTTP/\S+ ([1-5][0-9]{2})(?:\s|$)~', $line)) {
                $result['headers'] = [];
                $result['cookies'] = [];
                $result['complete'] = false;
                return strlen($line);
            }
            if (trim($line) === '') {
                $result['complete'] = true;
                return 0;
            }
            if (!str_contains($line, ':')) {
                return strlen($line);
            }
            [$name, $value] = explode(':', $line, 2);
            $key = strtolower(trim($name));
            $val = trim($value);
            if ($key === 'set-cookie') {
                $result['cookies'][] = $val;
            }
            if (!isset($result['headers'][$key])) {
                $result['headers'][$key] = $val;
            }
            return strlen($line);
        };
    }

    /** @param array<string, string> $headers */
    private function headersResult(array $headers): array
    {
        $notes = [];
        $status = HealthStatus::OK;
        $severity = 'none';

        $hsts = strtolower($headers['strict-transport-security'] ?? '');
        if ($hsts === '') {
            $status = HealthStatus::WARNING;
            $severity = 'medium';
            $notes[] = 'Strict-Transport-Security missing.';
        }

        $nosniff = strtolower($headers['x-content-type-options'] ?? '');
        if ($nosniff !== 'nosniff') {
            $status = HealthStatus::WARNING;
            $severity = 'medium';
            $notes[] = 'X-Content-Type-Options missing or not set to nosniff.';
        }

        $referrer = strtolower($headers['referrer-policy'] ?? '');
        if ($referrer === '') {
            $status = HealthStatus::WARNING;
            $severity = 'medium';
            $notes[] = 'Referrer-Policy missing.';
        }

        $xfo = strtolower($headers['x-frame-options'] ?? '');
        $csp = strtolower($headers['content-security-policy'] ?? '');
        if ($xfo === '' && !str_contains($csp, 'frame-ancestors')) {
            $status = HealthStatus::WARNING;
            $severity = 'medium';
            $notes[] = 'No framing protection (X-Frame-Options or CSP frame-ancestors) detected.';
        }

        $message = $status === HealthStatus::OK
            ? _t(__CLASS__ . '.HEADERS_OK', 'Core security headers are present for this response.')
            : _t(__CLASS__ . '.HEADERS_WARN', 'One or more core security headers are missing or weak for this response.');

        return HealthReport::singleton()->result(self::ID_HEADERS, _t(__CLASS__ . '.HEADERS_TITLE', 'Security response headers'), $status, $message, [
            'category' => 'security.headers',
            'source' => HealthReport::SOURCE_EXTERNAL,
            'state' => $status === HealthStatus::OK ? HealthReport::STATE_PASSED : HealthReport::STATE_RECOMMENDATION,
            'severity' => $severity,
            'priority' => $status === HealthStatus::OK ? 'low' : 'medium',
            'target' => 'Public HTTPS response headers',
            'evidence' => $notes === [] ? 'HSTS, nosniff, Referrer-Policy and framing protection detected.' : implode(' ', $notes),
            'impact' => 'Missing headers can weaken browser-side transport and content protections.',
            'remediation' => 'Configure baseline security headers at app and edge layers, then verify effective delivery.',
            'limitations' => 'Single response sample only; other routes and caches may differ.',
            'reference' => 'https://developer.mozilla.org/en-US/docs/Web/Security/Practical_implementation_guides',
        ]);
    }

    /** @param list<string> $cookies */
    private function cookiesResult(array $cookies): array
    {
        $sessionLike = array_values(array_filter($cookies, static function (string $cookie): bool {
            $name = strtolower(trim((string) explode('=', $cookie, 2)[0]));
            return str_contains($name, 'sess') || str_contains($name, 'session') || str_contains($name, 'phpsessid');
        }));

        if ($sessionLike === []) {
            return HealthReport::singleton()->result(self::ID_COOKIES, _t(__CLASS__ . '.COOKIES_TITLE', 'Session cookie flags'), HealthStatus::UNKNOWN,
                _t(__CLASS__ . '.COOKIES_UNKNOWN', 'No session-like cookies were observed in this response.'), [
                    'category' => 'security.cookies',
                    'source' => HealthReport::SOURCE_EXTERNAL,
                    'state' => HealthReport::STATE_NOT_TESTABLE,
                    'severity' => 'low',
                    'priority' => 'low',
                    'target' => 'Set-Cookie response headers',
                    'evidence' => 'No cookie names matching session heuristics were present.',
                    'impact' => 'Session cookie policy cannot be assessed from this response.',
                    'remediation' => 'Probe an authenticated/session-establishing flow or mark check as not applicable.',
                    'limitations' => 'Not every endpoint sets session cookies; result is not a pass/fail claim.',
                ]);
        }

        $issues = [];
        foreach ($sessionLike as $cookie) {
            $lower = strtolower($cookie);
            if (!str_contains($lower, 'secure')) {
                $issues[] = 'Secure missing';
            }
            if (!str_contains($lower, 'httponly')) {
                $issues[] = 'HttpOnly missing';
            }
            if (!str_contains($lower, 'samesite=')) {
                $issues[] = 'SameSite missing';
            }
        }

        $status = $issues === [] ? HealthStatus::OK : HealthStatus::WARNING;
        $message = $status === HealthStatus::OK
            ? _t(__CLASS__ . '.COOKIES_OK', 'Observed session-like cookies include baseline flags.')
            : _t(__CLASS__ . '.COOKIES_WARN', 'Observed session-like cookies are missing one or more baseline flags.');

        return HealthReport::singleton()->result(self::ID_COOKIES, _t(__CLASS__ . '.COOKIES_TITLE', 'Session cookie flags'), $status, $message, [
            'category' => 'security.cookies',
            'source' => HealthReport::SOURCE_EXTERNAL,
            'state' => $status === HealthStatus::OK ? HealthReport::STATE_PASSED : HealthReport::STATE_RECOMMENDATION,
            'severity' => $status === HealthStatus::OK ? 'none' : 'medium',
            'priority' => $status === HealthStatus::OK ? 'low' : 'medium',
            'target' => 'Set-Cookie response headers',
            'evidence' => $issues === []
                ? 'Observed session-like cookies include Secure, HttpOnly and SameSite.'
                : implode('; ', array_slice($issues, 0, 5)),
            'impact' => 'Missing cookie flags can increase exposure to session theft and CSRF risks.',
            'remediation' => 'Set Secure, HttpOnly and context-appropriate SameSite for session cookies.',
            'limitations' => 'Heuristic cookie-name matching and single-response sampling.',
            'reference' => 'https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie',
        ]);
    }

    private function notTestable(string $evidence): array
    {
        return HealthReport::singleton()->result(
            self::ID_HEADERS,
            _t(__CLASS__ . '.HEADERS_TITLE', 'Security response headers'),
            HealthStatus::UNKNOWN,
            _t(__CLASS__ . '.UNAVAILABLE', 'External header checks could not be completed for this destination.'),
            [
                'category' => 'security.headers',
                'source' => HealthReport::SOURCE_EXTERNAL,
                'state' => HealthReport::STATE_NOT_TESTABLE,
                'severity' => 'low',
                'priority' => 'low',
                'target' => 'Public HTTPS response headers',
                'evidence' => $evidence,
                'impact' => 'Security header and cookie posture remains unassessed.',
                'remediation' => 'Retry against an explicitly reachable public endpoint.',
                'limitations' => 'Network and destination validation safeguards may intentionally block this check.',
            ]
        );
    }
}


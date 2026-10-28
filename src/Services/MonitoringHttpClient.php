<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Helpers\MonitoringURL;
use Closure;
use CurlHandle;
use DomainException;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;
use UnexpectedValueException;

/**
 * Bounded, non-proxied, DNS-pinned customer credential requests.
 *
 * Requires ext-curl with HTTPS support. No redirect, retry or ambient proxy may
 * send the token to a destination other than the validated TLS host.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class MonitoringHttpClient
{
    use Injectable;

    /** Fixed producer endpoint relative to the installation base. */
    public const ENDPOINT = '/developer-tools/project-information';
    /** Total transfer budget in seconds. */
    public const TIMEOUT = 30;
    /** Connection establishment budget in seconds. */
    public const CONNECT_TIMEOUT = 5;

    /** Fetches raw inventory without ever returning remote error pages as failures. */
    public function fetch(string $baseURL, string $token): string
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('Monitoring requires ext-curl.');
        }
        if (!$this->validToken($token)) {
            throw new UnexpectedValueException('Monitoring credential is invalid.');
        }
        [$url, $host, $port, $addresses] = $this->destination($baseURL);
        return $this->request($url, $host, $port, $addresses[0], $token);
    }

    /** Allows visible header-safe bearer credentials with a fixed upper bound. */
    public static function validToken(string $token): bool
    {
        return strlen($token) <= 4096 && preg_match('/\A[\x21-\x7e]+\z/D', $token) === 1;
    }

    /** Revalidates the saved URL and every A/AAAA answer immediately before connecting. */
    private function destination(string $baseURL): array
    {
        try {
            $validator = MonitoringURL::singleton();
            $url = $validator->normalize($baseURL);
            $host = (string) parse_url($url, PHP_URL_HOST);
            $port = (int) (parse_url($url, PHP_URL_PORT) ?? 443);
            return [$url . self::ENDPOINT, $host, $port, $validator->publicAddresses($host)];
        } catch (DomainException $exception) {
            throw new UnexpectedValueException('Monitoring destination is invalid.');
        }
    }

    /** Limits bytes before appending and releases cURL resources on every path. */
    private function request(string $url, string $host, int $port, string $address, string $token): string
    {
        $handle = curl_init();
        if (!$handle instanceof CurlHandle) {
            throw new RuntimeException('Monitoring HTTP client could not be initialized.');
        }
        $body = '';
        try {
            curl_setopt_array($handle, $this->options($url, $host, $port, $address, $token));
            curl_setopt($handle, CURLOPT_WRITEFUNCTION, $this->bodyWriter($body));
            return $this->readResponse($handle, $body);
        } finally {
            curl_close($handle);
        }
    }

    /** Enforces the byte limit before allocating the next body chunk. */
    protected function bodyWriter(string &$body): Closure
    {
        return static function (CurlHandle $curl, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > MonitoringInventory::MAX_BYTES) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        };
    }

    /** All transport failures, redirects and non-success statuses have fixed error text. */
    private function readResponse(CurlHandle $handle, string &$body): string
    {
        [$success, $status] = $this->transfer($handle);
        if ($success === false || $status !== 200) {
            throw new UnexpectedValueException('Monitoring HTTP request failed.');
        }
        return $body;
    }

    /** Transport seam for tests that must never contact a live website. */
    protected function transfer(CurlHandle $handle): array
    {
        $success = curl_exec($handle);
        return [$success, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE)];
    }

    /** Pins the connection, preserving host-based TLS/SNI verification. */
    protected function options(string $url, string $host, int $port, string $address, string $token): array
    {
        $destination = str_contains($address, ':') ? '[' . $address . ']' : $address;
        return [
            CURLOPT_URL => $url, CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT, CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $destination],
            CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
            CURLOPT_NETRC => CURL_NETRC_IGNORED, CURLOPT_FAILONERROR => false,
            CURLOPT_HEADER => false, CURLOPT_RETURNTRANSFER => false,
        ];
    }
}

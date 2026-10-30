<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Helpers\MonitoringURL;
use Closure;
use CurlHandle;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;
use UnexpectedValueException;

/**
 * Credential-free, DNS-pinned HTTP probe which stops after the response headers.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class MonitoringRedirectClient
{
    use Injectable;

    /** Maximum connection time in seconds. */
    public const CONNECT_TIMEOUT = 3;
    /** Maximum transfer time in seconds. */
    public const TIMEOUT = 5;
    /** Bounds all response headers, including informational responses. */
    public const MAX_HEADER_BYTES = 16384;

    /** Reuses destination protection without weakening the credentialed HTTPS client. */
    public function probe(string $baseURL): array
    {
        $validator = MonitoringURL::singleton();
        $baseURL = $validator->normalize($baseURL);
        $host = (string) parse_url($baseURL, PHP_URL_HOST);
        $addresses = $validator->publicAddresses($host);
        $url = 'http://' . $host . (parse_url($baseURL, PHP_URL_PATH) ?: '/');
        return $this->request($url, $host, $addresses[0]);
    }

    /** Stops at the end of the final headers instead of downloading arbitrary HTTP pages. */
    private function request(string $url, string $host, string $address): array
    {
        $handle = curl_init();
        if (!$handle instanceof CurlHandle) {
            throw new RuntimeException('HTTP redirect probe could not be initialized.');
        }
        $response = ['status' => 0, 'locations' => [], 'complete' => false, 'invalid' => false];
        $bytes = 0;
        curl_setopt_array($handle, $this->options($url, $host, $address));
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, $this->headerReader($response, $bytes));
        $this->transfer($handle);
        if (!$response['complete']) {
            throw new UnexpectedValueException('HTTP redirect probe did not return complete headers.');
        }
        return $response;
    }

    /** No credentials, cookies, proxies, redirects, alternate protocols or persistent connections. */
    protected function options(string $url, string $host, string $address): array
    {
        $address = str_contains($address, ':') ? '[' . $address . ']' : $address;
        return [
            CURLOPT_URL => $url, CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => ['Accept: */*'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_NETRC => CURL_NETRC_IGNORED,
            CURLOPT_RESOLVE => [trim($host, '[]') . ':80:' . $address],
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT, CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
            CURLOPT_HEADER => false, CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static fn(CurlHandle $curl, string $chunk): int => 0,
        ];
    }

    /** Parses bounded header blocks; returning zero deliberately ends the GET before its body. */
    protected function headerReader(array &$response, int &$bytes): Closure
    {
        return function (CurlHandle $curl, string $line) use (&$response, &$bytes): int {
            $bytes += strlen($line);
            if ($bytes > self::MAX_HEADER_BYTES) {
                return 0;
            }
            if (preg_match('~^HTTP/\S+ ([1-5][0-9]{2})(?:\s|$)~', $line, $match)) {
                $response = ['status' => (int) $match[1], 'locations' => [], 'complete' => false, 'invalid' => false];
            } elseif (trim($line) === '' && $response['status'] >= 200) {
                $response['complete'] = true;
                return 0;
            } else {
                $this->readHeader($response, $line);
            }
            return strlen($line);
        };
    }

    /** Rejects ambiguous folded headers and retains only Location, never remote prose. */
    private function readHeader(array &$response, string $line): void
    {
        if (trim($line) === '') {
            return;
        }
        if (preg_match('/^[ \t]/', $line) || !str_contains($line, ':')) {
            $response['invalid'] = true;
        } elseif (strtolower(explode(':', $line, 2)[0]) === 'location') {
            $response['locations'][] = trim(explode(':', $line, 2)[1]);
        }
    }

    /** Test seam: production performs exactly one transfer and never follows its Location. */
    protected function transfer(CurlHandle $handle): void
    {
        curl_exec($handle);
    }
}

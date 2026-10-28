<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;

/**
 * Reads bounded public metadata from the fixed release provider endpoints.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class ReleaseMetadataClient
{
    use Injectable;

    /**
     * Fetches public JSON without forwarding customer credentials or redirects.
     */
    public function fetch(string $url): array
    {
        try {
            $response = (new Client())->request('GET', $url, [
                'connect_timeout' => 2, 'timeout' => 5, 'allow_redirects' => false,
                'read_timeout' => 2,
                'http_errors' => false, 'stream' => true, 'verify' => true,
                'headers' => ['User-Agent' => 'Silverstripe-DeveloperTools-Monitoring', 'Accept' => 'application/json'],
            ]);
            return $this->decodeResponse($response);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Release source request failed.', 0, $exception);
        }
    }

    /** Distinguishes unknown public packages from failed source requests. */
    private function decodeResponse(ResponseInterface $response): array
    {
        try {
            if ($response->getStatusCode() === 404) {
                throw new ReleaseUnavailableException('No public release metadata is available.');
            }
            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException('Release source returned HTTP ' . $response->getStatusCode() . '.');
            }
            return $this->decode($this->readBody($response->getBody()));
        } finally {
            $response->getBody()->close();
        }
    }

    /** Reads streamed bodies completely without allowing an unbounded download. */
    private function readBody(StreamInterface $stream): string
    {
        $body = '';
        $deadline = microtime(true) + 5;
        while (!$stream->eof() && strlen($body) <= 8 * 1024 * 1024) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Release source response timed out.');
            }
            $part = $stream->read(65536);
            if ($part === '' && !$stream->eof()) {
                throw new RuntimeException('Release source response stalled.');
            }
            $body .= $part;
        }
        return $body;
    }

    /**
     * Rejects oversized or invalid provider responses without logging response data.
     */
    private function decode(string $body): array
    {
        if (strlen($body) > 8 * 1024 * 1024) {
            throw new RuntimeException('Release source response exceeded the size limit.');
        }
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Release source returned invalid JSON.', 0, $exception);
        }
        if (!is_array($data)) {
            throw new RuntimeException('Release source returned an invalid document.');
        }
        return $data;
    }
}

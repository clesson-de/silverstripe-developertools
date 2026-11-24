<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus;
use Clesson\Silverstripe\DeveloperTools\Helpers\MonitoringURL;
use DomainException;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;

/**
 * Checks one public HTTPS endpoint certificate with bounded network cost.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class ExternalCertificateCheck
{
    use Injectable;

    public const ID = 'external.tls_certificate';
    private const CONNECT_TIMEOUT = 5;
    private const EXPIRY_WARNING_DAYS = 15;

    public function check(string $baseURL, float $deadline): array
    {
        if (microtime(true) >= $deadline) {
            return $this->result(
                HealthStatus::UNKNOWN,
                _t(__CLASS__ . '.BUDGET', 'Certificate check was skipped because the execution budget expired.'),
                [
                    'state' => HealthReport::STATE_NOT_TESTABLE,
                    'evidence' => 'Shared execution budget expired before certificate probe.',
                ]
            );
        }

        try {
            $validator = MonitoringURL::singleton();
            $normalized = $validator->normalize($baseURL);
            $host = trim((string) parse_url($normalized, PHP_URL_HOST), '[]');
            $port = (int) (parse_url($normalized, PHP_URL_PORT) ?: 443);
            $validator->publicAddresses($host);
            $remaining = max(1.0, min((float) self::CONNECT_TIMEOUT, $deadline - microtime(true)));
            $probe = $this->probe($host, $port, $remaining);
            $parsed = @openssl_x509_parse($probe['certificate'], false);
            if (!is_array($parsed) || !isset($parsed['validTo_time_t'])) {
                return $this->result(
                    HealthStatus::UNKNOWN,
                    _t(__CLASS__ . '.UNKNOWN', 'Certificate could not be parsed reliably.'),
                    [
                        'state' => HealthReport::STATE_NOT_TESTABLE,
                        'target' => $normalized,
                        'evidence' => 'TLS handshake succeeded but X.509 parse did not return required fields.',
                    ]
                );
            }

            $validTo = (int) $parsed['validTo_time_t'];
            $days = (int) floor(($validTo - $this->now()) / 86400);
            if ($days < 0) {
                return $this->result(
                    HealthStatus::ERROR,
                    _t(__CLASS__ . '.EXPIRED', 'TLS certificate is expired.'),
                    [
                        'state' => HealthReport::STATE_PROBLEM,
                        'severity' => 'high',
                        'priority' => 'high',
                        'target' => $normalized,
                        'evidence' => sprintf('Peer certificate expired %d day(s) ago.', abs($days)),
                    ]
                );
            }
            if ($days <= self::EXPIRY_WARNING_DAYS) {
                return $this->result(
                    HealthStatus::WARNING,
                    _t(__CLASS__ . '.EXPIRING', 'TLS certificate expires soon.'),
                    [
                        'state' => HealthReport::STATE_RECOMMENDATION,
                        'severity' => 'medium',
                        'priority' => 'high',
                        'target' => $normalized,
                        'evidence' => sprintf('Peer certificate valid for %d more day(s).', $days),
                    ]
                );
            }

            return $this->result(
                HealthStatus::OK,
                _t(__CLASS__ . '.OK', 'TLS certificate is valid and currently trusted.'),
                [
                    'state' => HealthReport::STATE_PASSED,
                    'severity' => 'none',
                    'priority' => 'low',
                    'target' => $normalized,
                    'evidence' => sprintf(
                        'Peer verification succeeded, certificate valid for %d day(s), chain entries: %d.',
                        $days,
                        $probe['chain_count']
                    ),
                ]
            );
        } catch (DomainException $exception) {
            return $this->result(
                HealthStatus::UNKNOWN,
                _t(__CLASS__ . '.UNAVAILABLE', 'Certificate check could not run for this destination.'),
                [
                    'state' => HealthReport::STATE_NOT_TESTABLE,
                    'evidence' => 'Destination rejected by public endpoint validation.',
                ]
            );
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            $verifyFailure = stripos($message, 'certificate verify failed') !== false;
            return $this->result(
                $verifyFailure ? HealthStatus::ERROR : HealthStatus::UNKNOWN,
                $verifyFailure
                    ? _t(__CLASS__ . '.VERIFY_FAILED', 'TLS certificate verification failed.')
                    : _t(__CLASS__ . '.UNAVAILABLE', 'Certificate check could not run for this destination.'),
                [
                    'state' => $verifyFailure ? HealthReport::STATE_PROBLEM : HealthReport::STATE_NOT_TESTABLE,
                    'severity' => $verifyFailure ? 'high' : 'low',
                    'priority' => $verifyFailure ? 'high' : 'low',
                    'evidence' => 'TLS connection failed before a trusted peer certificate could be confirmed.',
                ]
            );
        }
    }

    /** @return array{certificate: mixed, chain_count: int} */
    protected function probe(string $host, int $port, float $timeout): array
    {
        $address = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
                'SNI_server_name' => $host,
                'capture_peer_cert' => true,
                'capture_peer_cert_chain' => true,
                'disable_compression' => true,
            ],
        ]);
        $client = @stream_socket_client(
            'tls://' . $address . ':' . $port,
            $errno,
            $error,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($client)) {
            throw new RuntimeException($error ?: ('TLS connect failed (' . $errno . ')'));
        }
        $params = stream_context_get_params($client);
        fclose($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($cert === null) {
            throw new RuntimeException('TLS peer certificate was not provided.');
        }
        $chain = $params['options']['ssl']['peer_certificate_chain'] ?? [];
        return ['certificate' => $cert, 'chain_count' => is_array($chain) ? count($chain) : 0];
    }

    protected function now(): int
    {
        return time();
    }

    private function result(string $status, string $message, array $meta = []): array
    {
        return HealthReport::singleton()->result(
            self::ID,
            _t(__CLASS__ . '.TITLE', 'TLS certificate'),
            $status,
            $message,
            $meta + [
                'category' => 'security.transport',
                'source' => HealthReport::SOURCE_EXTERNAL,
                'target' => '',
                'impact' => 'Certificate issues can break confidentiality, authenticity or user trust.',
                'remediation' => 'Use a valid certificate chain for the public hostname and renew before expiry.',
                'limitations' => 'Single endpoint probe; alternate hostnames and edge paths may differ.',
                'reference' => 'https://developer.mozilla.org/en-US/docs/Web/Security',
            ]
        );
    }
}


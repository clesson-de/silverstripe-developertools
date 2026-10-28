<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use DomainException;
use JsonException;
use SilverStripe\Core\Injector\Injectable;
use stdClass;

/**
 * Reads legacy inventories and the version-two inventory/health envelope.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class MonitoringResponse
{
    use Injectable;

    /** Keeps legacy projects readable while rejecting unsupported protocol versions. */
    public function decode(string $body): array
    {
        if (strlen($body) > MonitoringInventory::MAX_BYTES) {
            throw new DomainException('Monitoring response exceeds the size limit.');
        }
        try {
            $data = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DomainException('Invalid monitoring JSON.');
        }
        if (!$data instanceof stdClass) {
            throw new DomainException('Monitoring response must be an object.');
        }
        return $this->document($data, $body);
    }

    /** Validates both sections before a successful attempt can be persisted. */
    private function document(stdClass $data, string $body): array
    {
        if (!property_exists($data, 'schema_version') || $data->schema_version === 1) {
            return ['inventory' => MonitoringInventory::singleton()->normalize($body), 'health_checks' => []];
        }
        if ($data->schema_version !== 2 || count(get_object_vars($data)) !== 3
            || !isset($data->inventory, $data->health_checks) || !$data->inventory instanceof stdClass
        ) {
            throw new DomainException('Unsupported monitoring response schema.');
        }
        return [
            'inventory' => MonitoringInventory::singleton()->normalize(json_encode($data->inventory, JSON_THROW_ON_ERROR)),
            'health_checks' => HealthReport::singleton()->normalize($data->health_checks),
        ];
    }
}

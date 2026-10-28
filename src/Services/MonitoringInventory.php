<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use DomainException;
use JsonException;
use SilverStripe\Core\Injector\Injectable;
use stdClass;

/**
 * Validates the bounded wire contract and produces deterministic inventory JSON.
 *
 * Unknown keys are rejected; only schema_version and captured_at are volatile.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class MonitoringInventory
{
    use Injectable;

    /** Maximum uncompressed response length. */
    public const MAX_BYTES = 1048576;
    /** Maximum number of installed packages. */
    public const MAX_PACKAGES = 2000;
    /** Composer dependency list key. */
    public const PACKAGES = 'composer_packages';
    /** Database family metadata. */
    public const DATABASE_PRODUCT = 'database_product';
    /** Required runtime version keys. */
    public const VERSIONS = ['php_version', 'silverstripe_version', 'database_version'];
    /** Allowed database family values. */
    public const PRODUCTS = ['mysql', 'mariadb', 'unknown'];
    /** Composer package name key. */
    public const PACKAGE_NAME = 'name';
    /** Composer package version key. */
    public const PACKAGE_VERSION = 'version';
    /** Explicitly ignored transport metadata. */
    private const METADATA = ['schema_version', 'captured_at'];

    /** Decodes and validates the wire response, excluding transport metadata. */
    public function normalize(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new DomainException('Inventory exceeds the size limit.');
        }
        try {
            $payload = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DomainException('Invalid inventory JSON.');
        }
        if (!$payload instanceof stdClass) {
            throw new DomainException('Inventory must be an object.');
        }
        return $this->canonicalize(get_object_vars($payload));
    }

    /** Revalidates arrays before encoding so callers cannot persist arbitrary data. */
    public function encode(array $inventory): string
    {
        try {
            return json_encode($this->canonicalize($inventory), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DomainException('Invalid inventory encoding.');
        }
    }

    /** Validates the known keys in stable order. */
    private function canonicalize(array $payload): array
    {
        $allowed = [...self::VERSIONS, self::PACKAGES, self::DATABASE_PRODUCT, ...self::METADATA];
        if (array_diff(array_keys($payload), $allowed)) {
            throw new DomainException('Unknown inventory fields.');
        }
        $inventory = [];
        foreach (self::VERSIONS as $key) {
            $inventory[$key] = $this->version($payload[$key] ?? null);
        }
        $inventory[self::DATABASE_PRODUCT] = $this->databaseProduct($payload, $inventory['database_version']);
        $inventory[self::PACKAGES] = $this->packages($payload[self::PACKAGES] ?? null);
        return $inventory;
    }

    /** Preserves meaningful version text while rejecting controls, markup and unbounded data. */
    private function version(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 160
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9 ._+~:\/@#()=-]*\z/D', $value) !== 1
            || trim($value) !== $value
        ) {
            throw new DomainException('Invalid inventory version.');
        }
        return $value;
    }

    /** Does not mistake an unqualified database version for MySQL. */
    private function databaseProduct(array $payload, string $version): string
    {
        if (array_key_exists(self::DATABASE_PRODUCT, $payload)) {
            $product = $payload[self::DATABASE_PRODUCT];
            if (!is_string($product) || !in_array($product, self::PRODUCTS, true)) {
                throw new DomainException('Invalid database product.');
            }
            return $product;
        }
        if (preg_match('/\bmariadb\b/i', $version) === 1) {
            return 'mariadb';
        }
        return preg_match('/\bmysql\b/i', $version) === 1 ? 'mysql' : 'unknown';
    }

    /** Rejects duplicate names and sorts the bounded dependency list. */
    private function packages(mixed $packages): array
    {
        if (!is_array($packages) || !array_is_list($packages) || count($packages) > self::MAX_PACKAGES) {
            throw new DomainException('Invalid package list.');
        }
        $sorted = [];
        foreach ($packages as $package) {
            $item = $this->package($package);
            $name = $item[self::PACKAGE_NAME];
            if (isset($sorted[$name])) {
                throw new DomainException('Duplicate package name.');
            }
            $sorted[$name] = $item;
        }
        ksort($sorted, SORT_STRING);
        return array_values($sorted);
    }

    /** Validates an exact Composer package object. */
    private function package(mixed $package): array
    {
        $package = $package instanceof stdClass ? get_object_vars($package) : $package;
        if (!is_array($package) || count($package) !== 2
            || array_diff(array_keys($package), [self::PACKAGE_NAME, self::PACKAGE_VERSION])
        ) {
            throw new DomainException('Invalid package fields.');
        }
        $name = $package[self::PACKAGE_NAME] ?? null;
        if (!is_string($name) || strlen($name) > 200
            || preg_match('/\A[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?\z/D', $name) !== 1
        ) {
            throw new DomainException('Invalid package name.');
        }
        return [self::PACKAGE_NAME => $name, self::PACKAGE_VERSION => $this->version($package[self::PACKAGE_VERSION] ?? null)];
    }
}

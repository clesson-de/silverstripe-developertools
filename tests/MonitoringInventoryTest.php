<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tests;

use Clesson\Silverstripe\DeveloperTools\Services\MonitoringInventory;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;

/**
 * Exercises the inventory wire contract without any website requests.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class MonitoringInventoryTest extends SapphireTest
{
    /** Reordering and volatile metadata do not change canonical data. */
    public function testCanonicalInventory(): void
    {
        $service = MonitoringInventory::create();
        $input = self::inventory();
        $canonical = $service->encode($input);
        $input['composer_packages'] = array_reverse($input['composer_packages']);
        $input['captured_at'] = '2026-01-01T00:00:00Z';
        $input['schema_version'] = 1;
        $normalized = $service->normalize(json_encode(array_reverse($input, true), JSON_THROW_ON_ERROR));
        self::assertSame($canonical, $service->encode($normalized));
        self::assertSame('mariadb', $normalized['database_product']);
        self::assertSame('silverstripe/cms', $normalized['composer_packages'][0]['name']);
    }

    /** Only explicit or unambiguous database family markers are classified. */
    public function testDatabaseIdentity(): void
    {
        $service = MonitoringInventory::create();
        $input = self::inventory();
        $input['database_version'] = '8.0.40';
        self::assertSame('unknown', $service->normalize(json_encode($input))['database_product']);
        $input['database_version'] = '8.0.40 MySQL Community Server';
        self::assertSame('mysql', $service->normalize(json_encode($input))['database_product']);
        $input['database_product'] = 'unknown';
        self::assertSame('unknown', $service->normalize(json_encode($input))['database_product']);
    }

    /** Any contract violation rejects the whole payload instead of discarding fields. */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayload(string $json): void
    {
        $this->expectException(DomainException::class);
        MonitoringInventory::create()->normalize($json);
    }

    /** Supplies malformed, oversized, unknown-key and invalid dependency cases. */
    public static function invalidPayloads(): array
    {
        $base = self::inventory();
        return [
            ['{'], ['[]'], ['null'], [str_repeat(' ', 1048577)],
            [json_encode($base + ['secret' => 'do-not-store'])],
            [json_encode(array_replace($base, ['php_version' => 80300]))],
            [json_encode(array_replace($base, ['database_product' => 'postgres']))],
            [json_encode(array_replace($base, ['composer_packages' => (object) []]))],
            [json_encode(array_replace($base, ['composer_packages' => [$base['composer_packages'][0], $base['composer_packages'][0]]]))],
            [json_encode(array_replace($base, ['composer_packages' => [['name' => 'bad', 'version' => '1.0']]]))],
            [json_encode(array_replace($base, ['composer_packages' => [['name' => 'a/b', 'version' => '1', 'token' => 'leak']]]))],
            [json_encode(array_replace($base, ['php_version' => "<script>\n"]))],
            [json_encode(array_replace($base, ['php_version' => str_repeat('x', 161)]))],
            [json_encode(array_replace($base, ['composer_packages' => array_fill(0, 2001, [])]))],
        ];
    }

    /** Reusable deterministic producer inventory. */
    public static function inventory(): array
    {
        return [
            'php_version' => '8.3.1',
            'silverstripe_version' => '6.1.0',
            'database_version' => '10.11.4-MariaDB',
            'composer_packages' => [
                ['name' => 'silverstripe/framework', 'version' => '6.1.0'],
                ['name' => 'silverstripe/cms', 'version' => '6.1.0'],
            ],
        ];
    }
}

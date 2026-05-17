<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Helpers;

use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;

/**
 * Reads and parses the project's composer.lock file.
 *
 * Returns all installed packages (including dev packages) as an ArrayList
 * of ArrayData objects, ready for use in a Silverstripe GridField.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Helpers
 */
class ComposerLockReader
{
    /**
     * Returns all installed Composer packages as an ArrayList.
     *
     * Each item is an ArrayData with the following fields:
     * - PackageName (string)
     * - Version     (string)
     * - Type        (string)
     * - Description (string)
     * - License     (string)
     *
     * @param string $search Optional search string to filter by name or description.
     * @return ArrayList
     */
    public static function getPackages(string $search = ''): ArrayList
    {
        $lockPath = BASE_PATH . '/composer.lock';

        if (!file_exists($lockPath)) {
            return ArrayList::create();
        }

        $content = file_get_contents($lockPath);

        if ($content === false) {
            return ArrayList::create();
        }

        /** @var array<string, mixed>|null $lock */
        $lock = json_decode($content, true);

        if (!is_array($lock)) {
            return ArrayList::create();
        }

        $packages = array_merge(
            $lock['packages'] ?? [],
            $lock['packages-dev'] ?? []
        );

        usort($packages, static fn (array $a, array $b): int => strcmp($a['name'] ?? '', $b['name'] ?? ''));

        $list = ArrayList::create();
        $search = strtolower(trim($search));

        foreach ($packages as $package) {
            $name        = (string) ($package['name'] ?? '');
            $version     = (string) ($package['version'] ?? '');
            $type        = (string) ($package['type'] ?? '');
            $description = (string) ($package['description'] ?? '');
            $license     = self::formatLicense($package['license'] ?? []);

            if ($search !== '' && !str_contains(strtolower($name), $search) && !str_contains(strtolower($description), $search)) {
                continue;
            }

            $list->push(ArrayData::create([
                'PackageName' => $name,
                'Version'     => $version,
                'Type'        => $type,
                'Description' => $description,
                'License'     => $license,
            ]));
        }

        return $list;
    }

    /**
     * Formats the license field from composer.lock.
     *
     * The license field can be a string or an array of strings.
     *
     * @param mixed $license
     * @return string
     */
    private static function formatLicense(mixed $license): string
    {
        if (is_array($license)) {
            return implode(', ', $license);
        }

        return (string) $license;
    }
}

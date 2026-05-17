<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Helpers;

use SilverStripe\Control\Director;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;

/**
 * Reads all routes configured via Director::rules and resolves
 * the responsible Composer package for each controller.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Helpers
 */
class RoutesReader
{
    /**
     * Returns all configured routes as an ArrayList.
     *
     * Each item is an ArrayData with:
     * - RoutePattern (string)  — the URL pattern, e.g. 'admin//$Action/$ID'
     * - Controller  (string)  — fully qualified controller class name
     * - Action      (string)  — specific action, or empty string
     * - Package     (string)  — Composer package name owning the controller
     *
     * @param string $search Optional search string to filter by pattern, controller or package.
     * @return ArrayList
     */
    public static function getRoutes(string $search = ''): ArrayList
    {
        $rules = Director::config()->get('rules') ?? [];
        $psr4Map = self::buildPsr4Map();

        $list = ArrayList::create();
        $search = strtolower(trim($search));

        foreach ($rules as $pattern => $target) {
            [$controller, $action] = self::parseTarget($target);
            $package = self::resolvePackage($controller, $psr4Map);

            if ($search !== '') {
                $haystack = strtolower($pattern . ' ' . $controller . ' ' . $package);
                if (!str_contains($haystack, $search)) {
                    continue;
                }
            }

            $list->push(ArrayData::create([
                'RoutePattern' => (string) $pattern,
                'Controller'   => $controller,
                'Action'       => $action,
                'Package'      => $package,
            ]));
        }

        return $list;
    }

    /**
     * Parses a Director route target into a controller class and optional action.
     *
     * The target can be:
     * - A string: 'Vendor\MyController'
     * - An array: ['Controller' => 'Vendor\MyController', 'Action' => 'index']
     *
     * @param mixed $target
     * @return array{0: string, 1: string}
     */
    private static function parseTarget(mixed $target): array
    {
        if (is_string($target)) {
            return [$target, ''];
        }

        if (is_array($target)) {
            $controller = (string) ($target['Controller'] ?? '');
            $action     = (string) ($target['Action'] ?? '');
            return [$controller, $action];
        }

        return ['', ''];
    }

    /**
     * Builds a map of PSR-4 namespace prefixes to Composer package names.
     *
     * Reads from composer.lock and merges packages and packages-dev.
     * The map is sorted by namespace length descending so that the most
     * specific match wins when resolving a controller's package.
     *
     * @return array<string, string>  namespace prefix => package name
     */
    private static function buildPsr4Map(): array
    {
        $lockPath = BASE_PATH . '/composer.lock';

        if (!file_exists($lockPath)) {
            return [];
        }

        $content = file_get_contents($lockPath);

        if ($content === false) {
            return [];
        }

        /** @var array<string, mixed>|null $lock */
        $lock = json_decode($content, true);

        if (!is_array($lock)) {
            return [];
        }

        $packages = array_merge(
            $lock['packages'] ?? [],
            $lock['packages-dev'] ?? []
        );

        $map = [];

        foreach ($packages as $package) {
            $name     = (string) ($package['name'] ?? '');
            $autoload = $package['autoload']['psr-4'] ?? [];

            foreach ($autoload as $namespace => $path) {
                // Normalise: ensure trailing backslash
                $namespace = rtrim((string) $namespace, '\\') . '\\';
                $map[$namespace] = $name;
            }
        }

        // Sort by namespace length descending — longest (most specific) match wins
        uksort($map, static fn (string $a, string $b): int => strlen($b) - strlen($a));

        return $map;
    }

    /**
     * Resolves the Composer package name for a given controller class.
     *
     * Matches the controller's fully qualified class name against the
     * PSR-4 namespace map, returning the first (most specific) match.
     *
     * @param string               $controller Fully qualified class name.
     * @param array<string,string> $psr4Map    Namespace prefix → package name.
     * @return string Package name, or empty string if not found.
     */
    private static function resolvePackage(string $controller, array $psr4Map): string
    {
        foreach ($psr4Map as $namespace => $package) {
            if (str_starts_with($controller, $namespace)) {
                return $package;
            }
        }

        return '';
    }
}


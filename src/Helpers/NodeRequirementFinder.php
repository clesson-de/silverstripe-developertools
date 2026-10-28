<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Helpers;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SilverStripe\Core\Injector\Injectable;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Bounded project search without entering dependency, asset or symlink directories.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Helpers
 */
class NodeRequirementFinder
{
    use Injectable;

    /** Maximum directory entries visited per search. */
    public const MAX_ENTRIES = 20000;
    /** Maximum independent Node builds reported. */
    public const MAX_FILES = 20;

    /** Returns project-relative paths and an explicit completeness flag. */
    public function find(string $root, float $deadline): array
    {
        $files = [];
        try {
            $complete = $this->scan($root, $deadline, $files);
        } catch (UnexpectedValueException $exception) {
            $complete = false;
        }
        sort($files, SORT_STRING);
        return ['files' => $files, 'complete' => $complete];
    }

    /** Retains discovered paths when a bounded scan cannot complete. */
    private function scan(string $root, float $deadline, array &$files): bool
    {
        $visited = 0;
        foreach ($this->entries($root) as $file) {
            if (++$visited > self::MAX_ENTRIES || microtime(true) >= $deadline) {
                return false;
            }
            if ($file->isFile() && $file->getFilename() === '.nvmrc') {
                if (count($files) >= self::MAX_FILES) {
                    return false;
                }
                $files[] = substr($file->getPathname(), strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
            }
        }
        return true;
    }

    /** SELF_FIRST makes directory traversal itself count towards the search limit. */
    private function entries(string $root): RecursiveIteratorIterator
    {
        $iterator = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $filtered = new RecursiveCallbackFilterIterator($iterator, static function (SplFileInfo $file): bool {
            return !$file->isLink()
                && !($file->isDir() && in_array(strtolower($file->getFilename()), ['assets', 'vendor'], true));
        });
        return new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::SELF_FIRST);
    }
}

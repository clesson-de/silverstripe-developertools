<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use DirectoryIterator;
use RuntimeException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use UnexpectedValueException;

class ProjectLogReader
{
    use Configurable;
    use Injectable;

    private const MAX_BYTES = 1048576;

    private static string $directory = '';

    private function directory(): string
    {
        $directory = static::config()->get('directory') ?: BASE_PATH . '/logs';
        $real = realpath($directory);
        if ($real === false || !is_dir($real) || !is_readable($real)) {
            throw new RuntimeException('The project log directory is unavailable or unreadable.');
        }
        return $real;
    }

    /** @return list<string> */
    public function files(): array
    {
        $files = [];
        try {
            foreach (new DirectoryIterator($this->directory()) as $entry) {
                if (!$entry->isLink() && $entry->isFile() && preg_match('/\.log$/i', $entry->getFilename())) {
                    $files[] = $entry->getFilename();
                }
            }
        } catch (UnexpectedValueException $exception) {
            throw new RuntimeException('The project log directory cannot be listed.', 0, $exception);
        }
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        return $files;
    }

    /** @return array{content: string, truncated: bool}|null */
    public function read(string $name): ?array
    {
        $path = $this->path($name);
        if ($path === null) {
            return null;
        }
        if (!is_readable($path)) {
            throw new RuntimeException('The selected log file is unreadable.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The selected log file could not be opened.');
        }
        try {
            $stat = fstat($handle);
            clearstatcache(true, $path);
            $pathStat = @lstat($path);
            if ($stat === false || $pathStat === false || is_link($path)
                || $stat['dev'] !== $pathStat['dev'] || $stat['ino'] !== $pathStat['ino']) {
                throw new RuntimeException('The selected log file could not be inspected.');
            }
            $truncated = $stat['size'] > self::MAX_BYTES;
            if ($truncated && fseek($handle, -self::MAX_BYTES, SEEK_END) !== 0) {
                throw new RuntimeException('The selected log file could not be read.');
            }
            $content = stream_get_contents($handle, self::MAX_BYTES);
            if ($content === false) {
                throw new RuntimeException('The selected log file could not be read.');
            }
            return ['content' => $content, 'truncated' => $truncated];
        } finally {
            fclose($handle);
        }
    }

    public function clear(string $name): bool
    {
        $path = $this->path($name);
        if ($path === null) {
            return false;
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('The selected log file could not be opened.');
        }
        try {
            $stat = fstat($handle);
            clearstatcache(true, $path);
            $pathStat = @lstat($path);
            if ($stat === false || $pathStat === false || is_link($path)
                || $stat['dev'] !== $pathStat['dev'] || $stat['ino'] !== $pathStat['ino']) {
                throw new RuntimeException('The selected log file could not be inspected.');
            }
            if (!ftruncate($handle, 0) || fseek($handle, 0) !== 0 || !fflush($handle)) {
                throw new RuntimeException('The selected log file could not be reset.');
            }
            return true;
        } finally {
            fclose($handle);
        }
    }

    private function path(string $name): ?string
    {
        if ($name === '' || basename($name) !== $name || str_contains($name, '\\')
            || str_contains($name, "\0") || !preg_match('/\.log$/i', $name)) {
            return null;
        }
        $directory = $this->directory();
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_link($path) || !is_file($path) || realpath($path) !== $path) {
            return null;
        }
        return $path;
    }
}

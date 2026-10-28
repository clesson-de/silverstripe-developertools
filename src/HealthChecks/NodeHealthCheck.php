<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\HealthChecks;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus as S;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReport;
use Clesson\Silverstripe\DeveloperTools\Services\NodeSupportService;
use Clesson\Silverstripe\DeveloperTools\Helpers\NodeRequirementFinder;
use SilverStripe\Control\Director;

/**
 * Evaluates discovered project .nvmrc files without executing Node.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage HealthChecks
 */
class NodeHealthCheck implements HealthCheck
{
    /** Limits local file reads and honours the shared execution deadline. */
    public function run(float $deadline): array
    {
        $search = NodeRequirementFinder::singleton()->find(Director::baseFolder(), min($deadline, microtime(true) + 2));
        $results = [];
        foreach ($search['files'] as $path) {
            $results[] = $this->checkFile(substr(hash('sha256', $path), 0, 24), $path, $deadline);
        }
        if (!$search['complete']) {
            $results[] = HealthReport::singleton()->result('node.discovery', _t(__CLASS__ . '.TITLE', 'Node build requirement'),
                S::UNKNOWN, _t(__CLASS__ . '.INCOMPLETE', 'The .nvmrc search was incomplete due to limits or unreadable directories.'));
        }
        return $results;
    }

    /** Invalid, unreadable, oversized or escaping discovered files remain explicitly unknown. */
    private function checkFile(string $id, string $path, float $deadline): array
    {
        $version = $this->readVersion($path);
        $outcome = [S::UNKNOWN, _t(__CLASS__ . '.UNAVAILABLE', 'Discovered .nvmrc is unreadable, outside the project or invalid.')];
        if ($version !== null && microtime(true) < $deadline) {
            $outcome = NodeSupportService::singleton()->assess($version);
        } elseif ($version !== null) {
            $outcome = [S::UNKNOWN, _t(__CLASS__ . '.BUDGET', 'Node support lookup skipped because the execution budget expired.')];
        }
        $title = _t(__CLASS__ . '.TITLE', 'Node build requirement') . ' (' . $id . ')';
        if ($version !== null) {
            $title .= ': ' . $version;
        }
        $relative = preg_replace('/[^\x20-\x7e]|[<>]/', '?', $path);
        $message = substr($relative, 0, 250) . ': ' . $outcome[1];
        return HealthReport::singleton()->result('node.' . $id, $title, $outcome[0], $message);
    }

    /** Resolves symlinks and confines reads to real files within the project root. */
    private function readVersion(string $path): ?string
    {
        $root = realpath(Director::baseFolder());
        $file = realpath(Director::baseFolder() . '/' . $path);
        if (!$root || !$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)
            || basename($file) !== '.nvmrc' || !is_file($file) || !is_readable($file)
        ) {
            return null;
        }
        $content = @file_get_contents($file, false, null, 0, 129);
        if ($content === false || strlen($content) > 128) {
            return null;
        }
        $version = trim($content);
        return preg_match('/\A[a-zA-Z0-9.*\/_-][a-zA-Z0-9.*\/_-]{0,63}\z/D', $version) ? $version : null;
    }
}

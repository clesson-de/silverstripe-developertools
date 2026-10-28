<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus;
use Clesson\Silverstripe\DeveloperTools\HealthChecks\HealthCheck;
use Clesson\Silverstripe\DeveloperTools\HealthChecks\NodeHealthCheck;
use Clesson\Silverstripe\DeveloperTools\HealthChecks\RuntimeHealthCheck;
use LogicException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * Runs trusted registered checks with a cooperative total execution budget.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class HealthCheckService
{
    use Injectable;
    use Configurable;

    /** @config Trusted check classes; projects may register additional implementations. */
    private static array $checks = [
        'runtime' => RuntimeHealthCheck::class,
        'node' => NodeHealthCheck::class,
    ];
    /** Cooperative wall-clock budget in seconds, not a PHP execution sandbox. */
    public const BUDGET = 12;

    /** Unexpected programming errors propagate rather than being disguised as healthy results. */
    public function collect(): array
    {
        $deadline = microtime(true) + self::BUDGET;
        $results = [];
        foreach (static::config()->get('checks') as $class) {
            array_push($results, ...$this->execute($class, $deadline));
            if (microtime(true) >= $deadline) {
                break;
            }
        }
        return HealthReport::singleton()->normalize($results);
    }

    /** Validates configured implementations and discards results arriving after the deadline. */
    private function execute(string $class, float $deadline): array
    {
        if (microtime(true) >= $deadline) {
            return [$this->budgetResult()];
        }
        $check = Injector::inst()->get($class);
        if (!$check instanceof HealthCheck) {
            throw new LogicException('Registered healthcheck must implement HealthCheck.');
        }
        $rows = $check->run($deadline);
        return microtime(true) >= $deadline ? [$this->budgetResult()] : $rows;
    }

    /** Uses a reserved stable ID for incomplete reports. */
    private function budgetResult(): array
    {
        return HealthReport::singleton()->result('system.budget', _t(__CLASS__ . '.TITLE', 'Healthchecks'),
            HealthStatus::UNKNOWN, _t(__CLASS__ . '.BUDGET', 'Execution budget exceeded; some checks were not run.'));
    }
}

<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\HealthChecks;

/**
 * Trusted project checks must bound all I/O and honour the absolute deadline.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage HealthChecks
 */
interface HealthCheck
{
    /**
     * Returns safe public results, never secrets, file contents or absolute paths.
     *
     * @return list<array{id: string, title: string, status: string, message: string, checked_at: string}>
     */
    public function run(float $deadline): array;
}

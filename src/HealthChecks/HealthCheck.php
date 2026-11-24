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
     * @return list<array{
     *     id: string,
     *     title: string,
     *     status: string,
     *     message: string,
     *     checked_at: string,
     *     category?: string,
     *     source?: string,
     *     state?: string,
     *     severity?: string,
     *     priority?: string,
     *     evidence?: string,
     *     target?: string,
     *     impact?: string,
     *     remediation?: string,
     *     limitations?: string,
     *     reference?: string
     * }>
     */
    public function run(float $deadline): array;
}

<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use RuntimeException;

/**
 * A product or private package has no assessable public stable release.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class ReleaseUnavailableException extends RuntimeException
{
}

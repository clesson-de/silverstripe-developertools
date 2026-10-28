<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Helpers;

use DomainException;
use SilverStripe\Control\Email\Email;

/**
 * Parses the shared comma-separated notification recipients without dropping errors.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Helpers
 */
class MonitoringRecipients
{
    /** Allows an empty list to disable notifications; rejects malformed addresses. */
    public static function parse(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }
        $addresses = array_map('trim', explode(',', $value));
        if (count($addresses) > 50 || strlen($value) > 10000) {
            throw new DomainException('Invalid notification recipients.');
        }
        foreach ($addresses as $address) {
            if (preg_match('/[\r\n]/', $address) || !Email::is_valid_address($address)) {
                throw new DomainException('Invalid notification recipients.');
            }
        }
        return array_values(array_unique($addresses));
    }
}

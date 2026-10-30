<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Helpers;

use DomainException;
use SilverStripe\Control\Email\Email;
use SilverStripe\Security\Member;

/**
 * Resolves current member email addresses without silently dropping invalid recipients.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Helpers
 */
class MonitoringRecipients
{
    /**
     * Deduplicates addresses and fails explicitly if a selected member has no valid email.
     *
     * @param iterable<Member> $members
     * @return string[]
     */
    public static function resolve(iterable $members): array
    {
        $addresses = [];
        foreach ($members as $member) {
            $address = trim((string) $member->Email);
            if (preg_match('/[\r\n]/', $address) || !Email::is_valid_address($address)) {
                throw new DomainException(_t(__CLASS__ . '.INVALID_MEMBER_EMAIL',
                    'Monitoring recipient member #{id} has no valid email address.', ['id' => $member->ID]));
            }
            $addresses[strtolower($address)] = $address;
        }
        return array_values($addresses);
    }
}

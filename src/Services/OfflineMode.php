<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Constants\OfflineField as F;
use Clesson\Silverstripe\DeveloperTools\Helpers\ToastIPRanges;
use Clesson\Silverstripe\DeveloperTools\Models\DeveloperToolsConfig;
use DomainException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Validates maintenance settings and evaluates inclusive-start/exclusive-end schedules.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class OfflineMode
{
    use Injectable;

    /** Validates optional IP exceptions even while offline mode is disabled. */
    public function validate(DeveloperToolsConfig $config, ValidationResult $result): void
    {
        try {
            ToastIPRanges::parse((string) $config->getField(F::IP_EXCEPTIONS));
        } catch (DomainException $exception) {
            $result->addFieldError(F::IP_EXCEPTIONS,
                _t(__CLASS__ . '.INVALID_IP', 'Enter valid IPv4/IPv6 addresses, CIDR networks or address ranges.'));
        }
        $this->validateDates($config, $result);
        $this->validatePage($config, $result);
    }

    /** Requires a usable export only when the maintenance switch is enabled. */
    private function validatePage(DeveloperToolsConfig $config, ValidationResult $result): void
    {
        if (!$config->getField(F::ACTIVE)) {
            return;
        }
        $exporter = OfflinePageExporter::create();
        $page = $exporter->publishedPage();
        if (!$page || $page->CanViewType !== 'Anyone' || !$exporter->content($page)) {
            $result->addFieldError(F::ACTIVE, _t(__CLASS__ . '.MISSING_PAGE',
                'Create and publish an unrestricted offline page with a successful static export before enabling offline mode.'));
        }
    }

    /** A bounded window must have a positive duration. */
    private function validateDates(DeveloperToolsConfig $config, ValidationResult $result): void
    {
        $start = $config->getField(F::START);
        $end = $config->getField(F::END);
        if ($start && $end && $end <= $start) {
            $result->addFieldError(F::END,
                _t(__CLASS__ . '.INVALID_DATES', 'The end must be later than the start.'));
        }
    }

    /** Empty date bounds are unlimited; inactive settings never block requests. */
    public function isInPeriod(DeveloperToolsConfig $config): bool
    {
        $now = DBDatetime::now()->getValue();
        $start = $config->getField(F::START);
        $end = $config->getField(F::END);
        return (bool) $config->getField(F::ACTIVE)
            && (!$start || $start <= $now)
            && (!$end || $end > $now);
    }

    /** Unlike audience filters, an empty exception list does not exempt anyone. */
    public function isExcepted(DeveloperToolsConfig $config, ?string $ip): bool
    {
        $rules = trim((string) $config->getField(F::IP_EXCEPTIONS));
        return $rules !== '' && ToastIPRanges::matches($rules, $ip);
    }
}

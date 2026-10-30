<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Forms;

use Clesson\Silverstripe\DeveloperTools\Constants\OfflineField as F;
use Clesson\Silverstripe\Hints\Forms\HintField;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TextareaField;

/**
 * Maintenance settings remain in the existing administrator-only singleton form.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Forms
 */
class OfflineModeTab
{
    /** Provides explicit scheduling and address-exception controls without selecting a page. */
    public function build(): Tab
    {
        /** @var HintField $offlineHintField */
        $offlineHintField = $this->hint();
        /** @var CheckboxField $activeField */
        $activeField = CheckboxField::create(F::ACTIVE, F::label(F::ACTIVE));
        /** @var DatetimeField $startField */
        $startField = DatetimeField::create(F::START, F::label(F::START));
        /** @var DatetimeField $endField */
        $endField = DatetimeField::create(F::END, F::label(F::END));
        /** @var TextareaField $exceptionsField */
        $exceptionsField = TextareaField::create(F::IP_EXCEPTIONS, F::label(F::IP_EXCEPTIONS));
        $exceptionsField->setDescription(_t(__CLASS__ . '.IP_HELP',
            'These addresses retain access to the normal website. Separate entries with commas or new lines. Examples: 192.0.2.1, 192.0.2.0/24, 2001:db8::/32, 192.0.2.10-192.0.2.20.'));
        return Tab::create('Offline', _t(__CLASS__ . '.TITLE', 'Offline mode'),
            $offlineHintField, $activeField, $startField, $endField, $exceptionsField);
    }

    /** Explains publication, access exceptions and the limits of an application-level gate. */
    private function hint(): HintField
    {
        return HintField::create('OfflineHint', _t(__CLASS__ . '.TITLE', 'Offline mode'),
            _t(__CLASS__ . '.HINT',
                'Database builds create and publish the single Offline page with English starter content if none exists. Edit it in the page tree and publish changes to refresh its standalone static HTML. Enabling offline mode serves this document with HTTP 503 at the requested URL, including APIs and monitoring. Start and end are optional; start is inclusive and end exclusive. Only the listed IP addresses bypass the gate, including for logged-in administrators. The admin area, login and required resources remain reachable. Purge or bypass existing website/CDN caches when enabling this feature. PHP, Silverstripe and the settings database must remain operational.'));
    }
}

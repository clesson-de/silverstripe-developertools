<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus;
use Clesson\Silverstripe\DeveloperTools\Constants\MonitoringStatus;
use Clesson\Silverstripe\DeveloperTools\Helpers\MonitoringRecipients;
use Clesson\Silverstripe\DeveloperTools\Models\DeveloperToolsConfig;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoringCheck;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\ArrayData;

/**
 * Sends one concise summary of the actual automatic run, never customer secrets.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class MonitoringNotificationService
{
    use Injectable;

    /** Transport failures propagate so a task never silently claims delivery. */
    public function send(array $outcomes): void
    {
        $recipients = MonitoringRecipients::resolve(DeveloperToolsConfig::currentRecord()->NotifyMembers());
        if (!$recipients) {
            return;
        }
        $rows = array_map(fn(array $outcome): array => $this->row($outcome), $outcomes);
        $attention = count(array_filter($rows, static fn(array $row): bool => $row['Attention']));
        $unknown = count(array_filter($rows, static fn(array $row): bool => $row['Unknown']));
        $this->deliver($recipients, $rows, $this->subject($attention, $unknown));
    }

    /** Uses the standard configured mail transport and HTML-escaped template. */
    private function deliver(array $recipients, array $rows, string $subject): void
    {
        $email = Email::create()->setTo($recipients)->setSubject($subject);
        $body = ArrayData::create(['Rows' => $rows, 'Summary' => $subject] + $this->presentation($email))
            ->renderWith('Clesson/Silverstripe/DeveloperTools/MonitoringNotification');
        $email->setBody((string) $body)->send();
    }

    /** Uses the actual From address and the central installation, not a customer domain. */
    private function presentation(Email $email): array
    {
        $sender = $email->getFrom()[0]->getAddress();
        return [
            'Heading' => _t(__CLASS__ . '.HEADING', 'Website Monitoring'),
            'Intro' => _t(__CLASS__ . '.INTRO', 'The automatic monitoring run has completed. Here is the current project overview.'),
            'Empty' => _t(__CLASS__ . '.EMPTY', 'No projects configured.'),
            'AutomaticNotice' => _t(__CLASS__ . '.AUTOMATIC_NOTICE', 'This message was sent automatically. Replies to this message cannot be processed. Please do not reply.'),
            'Origin' => _t(__CLASS__ . '.ORIGIN', 'Monitoring installation: {domain}', [
                'domain' => (string) parse_url(Director::absoluteBaseURL(), PHP_URL_HOST),
            ]),
            'Sender' => _t(__CLASS__ . '.SENDER', 'Sender domain: {domain}', [
                'domain' => substr($sender, strrpos($sender, '@') + 1),
            ]),
        ];
    }

    /** Uses the attempt passed by the runner, not a possibly newer concurrent check. */
    private function row(array $outcome): array
    {
        $website = $outcome['website'];
        $check = $outcome['check'];
        $status = $check ? $this->assessment($check) : [
            'Status' => _t(__CLASS__ . '.DISABLED', 'Disabled; not checked.'), 'Attention' => false, 'Unknown' => false,
        ];
        if ($check) {
            $status['Status'] .= $this->redirectStatus($check);
        }
        return ['Name' => $website->Name, 'URL' => $website->BaseURL] + $status + $this->badge($status, $check !== null);
    }

    /** Includes the independent transport outcome even when the inventory fetch failed. */
    private function redirectStatus(MonitoringCheck $check): string
    {
        foreach ($check->getHealthChecks() as $result) {
            if ($result['id'] === MonitoringRedirectCheck::ID) {
                return ' ' . _t(__CLASS__ . '.HTTP_REDIRECT', 'HTTP to HTTPS: {status}.', [
                    'status' => HealthStatus::label($result['status']),
                ]);
            }
        }
        return '';
    }

    /** Text and color both communicate status for clients without images or styles. */
    private function badge(array $status, bool $checked): array
    {
        if (!$checked) {
            return ['Badge' => _t(__CLASS__ . '.BADGE_DISABLED', 'Not checked'), 'Color' => '#64748b', 'Background' => '#f1f5f9'];
        }
        if ($status['Attention']) {
            return ['Badge' => _t(__CLASS__ . '.ATTENTION', 'Action required'), 'Color' => '#9a3412', 'Background' => '#fff7ed'];
        }
        return $status['Unknown']
            ? ['Badge' => _t(__CLASS__ . '.BADGE_UNKNOWN', 'Assessment incomplete'), 'Color' => '#854d0e', 'Background' => '#fefce8']
            : ['Badge' => _t(__CLASS__ . '.CLEAR', 'No action required'), 'Color' => '#166534', 'Background' => '#f0fdf4'];
    }

    /** Failed fetches and individual health warnings/errors require attention independently. */
    private function assessment(MonitoringCheck $check): array
    {
        if ($check->Status === MonitoringStatus::FAILED) {
            return ['Status' => _t(__CLASS__ . '.FAILED', 'Check failed.'), 'Attention' => true, 'Unknown' => false];
        }
        $versions = ReleaseVersionService::singleton()->actionRequired($check->Snapshot()->getInventory());
        $health = $check->getHealthStatus();
        $attention = $versions === true || in_array($health, [HealthStatus::WARNING, HealthStatus::ERROR], true);
        $unknown = $versions === null || $health === HealthStatus::UNKNOWN;
        $versionLabel = $versions === null ? _t(__CLASS__ . '.UNKNOWN', 'Not assessable')
            : ($versions ? _t(__CLASS__ . '.ATTENTION', 'Action required') : _t(__CLASS__ . '.CLEAR', 'No action required'));
        return ['Attention' => $attention, 'Unknown' => $unknown,
            'Status' => _t(__CLASS__ . '.STATUS', 'Versions: {versions}; health: {health}.', [
                'versions' => $versionLabel, 'health' => HealthStatus::label($health),
            ])];
    }

    /** Never describes missing comparisons as an all-clear. */
    private function subject(int $attention, int $unknown): string
    {
        if ($attention > 0) {
            return _t(__CLASS__ . '.SUBJECT_ACTION', 'Monitoring: action required for {count} project(s)', ['count' => $attention]);
        }
        return $unknown > 0
            ? _t(__CLASS__ . '.SUBJECT_UNKNOWN', 'Monitoring: assessment incomplete for {count} project(s)', ['count' => $unknown])
            : _t(__CLASS__ . '.SUBJECT_OK', 'Monitoring: no action required');
    }
}

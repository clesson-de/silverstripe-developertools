<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tests;

use Clesson\Silverstripe\DeveloperTools\Helpers\MonitoringRecipients;
use Clesson\Silverstripe\DeveloperTools\Models\DeveloperToolsConfig;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoredWebsite;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoringCheck;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoringSnapshot;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReport;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringNotificationService;
use Clesson\Silverstripe\DeveloperTools\Services\ReleaseVersionService;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Tests delivery using only the framework's captured test mail transport.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class MonitoringNotificationTest extends SapphireTest
{
    protected $usesDatabase = true;

    /** Uses a deterministic sender and two deduplicated recipients. */
    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(Email::class, 'admin_email', 'monitor@example.org');
        Config::modify()->set(Director::class, 'alternate_base_url', 'https://monitoring.example.net/');
        $config = DeveloperToolsConfig::currentRecord();
        $config->NotificationEmails = 'one@example.org, two@example.org, one@example.org';
        $config->write();
    }

    /** Mail contains all run outcomes, safely escaped, with no inventory or raw health text. */
    public function testCombinedSummary(): void
    {
        $outcomes = [
            $this->outcome('<Failed>', 'failed'),
            $this->outcome('Outdated', 'changed', 'ok'),
            $this->outcome('Health warning', 'unchanged', 'warning'),
            ['website' => MonitoredWebsite::create(['Name' => 'Disabled', 'BaseURL' => 'https://disabled.org']), 'check' => null],
        ];
        $release = $this->createMock(ReleaseVersionService::class);
        $release->method('actionRequired')->willReturnOnConsecutiveCalls(true, false);
        Injector::inst()->registerService($release, ReleaseVersionService::class);
        MonitoringNotificationService::create()->send($outcomes);
        $mail = self::findEmail('one@example.org,two@example.org');
        self::assertNotNull($mail);
        self::assertSame('Monitoring: action required for 3 project(s)', $mail['Subject']);
        foreach (['&lt;Failed&gt;', 'Outdated', 'Health warning', 'Disabled', 'not checked'] as $text) {
            self::assertStringContainsString($text, $mail['Content']);
        }
        self::assertStringNotContainsString('not-for-email', $mail['Content']);
        self::assertStringNotContainsString('php_version', $mail['Content']);
        self::assertStringNotContainsString('<ul', $mail['Content']);
        self::assertSame(4, substr_count($mail['Content'], 'class="monitoring-project-card"'));
        self::assertStringContainsString('background-color:#172554', $mail['Content']);
        self::assertStringContainsString('This message was sent automatically.', $mail['Content']);
        self::assertStringContainsString('Please do not reply.', $mail['Content']);
        self::assertStringContainsString('Monitoring installation: monitoring.example.net', $mail['Content']);
        self::assertStringContainsString('Sender domain: example.org', $mail['Content']);
    }

    /** Subject distinguishes an all-clear from unavailable assessments. */
    #[DataProvider('summaryStates')]
    public function testSummarySubject(?bool $versions, string $health, string $subject): void
    {
        $release = $this->createMock(ReleaseVersionService::class);
        $release->method('actionRequired')->willReturn($versions);
        Injector::inst()->registerService($release, ReleaseVersionService::class);
        MonitoringNotificationService::create()->send([$this->outcome('Project', 'unchanged', $health)]);
        self::assertEmailSent('one@example.org,two@example.org', null, $subject);
    }

    /** Covers version unknowns, health unknowns and individual errors. */
    public static function summaryStates(): array
    {
        return [
            [false, 'ok', 'Monitoring: no action required'],
            [null, 'ok', 'Monitoring: assessment incomplete for 1 project(s)'],
            [false, 'unknown', 'Monitoring: assessment incomplete for 1 project(s)'],
            [false, 'error', 'Monitoring: action required for 1 project(s)'],
        ];
    }

    /** Empty configuration disables delivery, not the check itself. */
    public function testDisabledNotifications(): void
    {
        $config = DeveloperToolsConfig::currentRecord();
        $config->NotificationEmails = '';
        $config->write();
        MonitoringNotificationService::create()->send([]);
        self::assertNull(self::findEmail('one@example.org,two@example.org'));
    }

    /** A transport failure remains visible to the scheduled task. */
    public function testDeliveryFailurePropagates(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')->willThrowException(new TransportException('Synthetic delivery failure'));
        Injector::inst()->registerService($mailer, MailerInterface::class);
        $this->expectException(TransportException::class);
        MonitoringNotificationService::create()->send([]);
    }

    /** CMS rejects malformed comma lists instead of silently discarding recipients. */
    #[DataProvider('invalidRecipients')]
    public function testInvalidRecipients(string $recipients): void
    {
        $config = DeveloperToolsConfig::currentRecord();
        $config->NotificationEmails = $recipients;
        self::assertFalse($config->validate()->isValid());
        $this->expectException(DomainException::class);
        MonitoringRecipients::parse($recipients);
    }

    /** Includes trailing commas, line injection and count limits. */
    public static function invalidRecipients(): array
    {
        return [
            ['invalid'], ['valid@example.org,'], ['one@example.org;two@example.org'],
            ["one@example.org\r\nBcc: two@example.org"], [str_repeat('one@example.org,', 50) . 'last@example.org'],
        ];
    }

    /** Builds in-memory attempts; no customer requests or real credentials. */
    private function outcome(string $name, string $status, string $health = 'ok'): array
    {
        $snapshot = $this->createMock(MonitoringSnapshot::class);
        $snapshot->method('getInventory')->willReturn([]);
        $check = $this->getMockBuilder(MonitoringCheck::class)->onlyMethods(['getComponent'])->getMock();
        $check->method('getComponent')->willReturn($snapshot);
        $check->Status = $status;
        $check->HealthResults = json_encode([HealthReport::create()->result('test', 'Test', $health, 'not-for-email')]);
        return ['website' => MonitoredWebsite::create(['Name' => $name, 'BaseURL' => 'https://example.org']), 'check' => $check];
    }
}

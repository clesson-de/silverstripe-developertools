<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tests;

use Clesson\Silverstripe\DeveloperTools\Constants\MonitoringStatus;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoredWebsite;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoringCheck;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoringSnapshot;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringCheckService;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringHttpClient;
use Clesson\Silverstripe\DeveloperTools\Services\ReleaseVersionService;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReport;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringNotificationService;
use Clesson\Silverstripe\DeveloperTools\Tasks\MonitoringCheckTask;
use LeKoala\Encrypt\EncryptHelper;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Forms\PasswordField;
use SilverStripe\ORM\Connect\NullDatabase;
use SilverStripe\ORM\Connect\DatabaseException;
use SilverStripe\ORM\Connect\MySQLDatabase;
use SilverStripe\ORM\Connect\MySQLiConnector;
use SilverStripe\ORM\Connect\MySQLQueryBuilder;
use SilverStripe\ORM\Connect\MySQLSchemaManager;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Security;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UnexpectedValueException;

/**
 * Database-backed backend contracts with mocked customer and release requests.
 *
 * Real commits are intentional: the service forbids ambient transactions and
 * verifies release refresh runs only after the website transaction commits.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class MonitoringBackendTest extends SapphireTest
{
    /** Synthetic credentials used only by these tests. */
    private const TOKEN = 'synthetic-monitoring-test-credential';
    /** @inheritdoc */
    protected $usesDatabase = true;
    /** @inheritdoc */
    protected $usesTransactions = false;
    /** Restored after each test. */
    private mixed $previousKey = null;
    /** Counts post-commit release refreshes, including unchanged inventories. */
    private int $refreshes = 0;

    /** Configures deterministic test-only encryption and never uses live clients. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousKey = Environment::getEnv('ENCRYPTION_KEY');
        Environment::setEnv('ENCRYPTION_KEY', str_repeat('a', 64));
        EncryptHelper::clearCipherSweet();
        $this->logInWithPermission('ADMIN');
        $release = $this->createMock(ReleaseVersionService::class);
        $release->method('refresh')->willReturnCallback(function (array $inventory): void {
            $this->refreshes++;
            self::assertSame(0, DB::get_conn()->transactionDepth());
            self::assertNotEmpty($inventory['composer_packages']);
        });
        Injector::inst()->registerService($release, ReleaseVersionService::class);
    }

    /** Restores process-global state. */
    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        EncryptHelper::clearCipherSweet();
        Environment::setEnv('ENCRYPTION_KEY', $this->previousKey);
        parent::tearDown();
    }

    /** Covers baseline, stable ordering, changed data, reverting and failure isolation. */
    public function testInventoryLifecycleAndTimestamps(): void
    {
        $website = $this->website();
        $inventory = MonitoringInventoryTest::inventory();
        $this->respondWith($inventory);
        DBDatetime::set_mock_now('2026-10-26 10:00:00');
        $first = MonitoringCheckService::create()->check($website);
        self::assertSame(MonitoringStatus::CHANGED, $first->Status);
        $this->assertSiteState($website, '2026-10-26 10:00:00', $first->SnapshotID, 1);
        $inventory['composer_packages'] = array_reverse($inventory['composer_packages']);
        $inventory['captured_at'] = '2026-10-26T10:01:00Z';
        $this->respondWith($inventory);
        DBDatetime::set_mock_now('2026-10-26 10:01:00');
        self::assertSame(MonitoringStatus::UNCHANGED, MonitoringCheckService::create()->check($website)->Status);
        $this->assertChangesAndReversion($website, $inventory);
        $this->assertFailedAttempt($website);
        self::assertSame(4, $this->refreshes);
    }

    /** Changing health outcomes and timestamps records attempts, never inventory snapshots. */
    public function testHealthHistoryIsIndependentOfInventory(): void
    {
        $website = $this->website();
        $inventory = MonitoringInventoryTest::inventory();
        $this->respondWith($inventory);
        $first = MonitoringCheckService::create()->check($website);
        foreach (['ok', 'warning', 'error'] as $status) {
            $health = [HealthReport::singleton()->result('test', 'Custom check', $status, 'Safe result.')];
            $this->respondWith(['schema_version' => 2, 'inventory' => $inventory, 'health_checks' => $health]);
            $check = MonitoringCheckService::create()->check($website);
            self::assertSame('unchanged', $check->Status);
            self::assertSame($first->SnapshotID, $check->SnapshotID);
            self::assertSame($health, $check->getHealthChecks());
            self::assertSame($status, $check->getHealthStatus());
        }
        self::assertSame(1, MonitoringSnapshot::get()->count());
        self::assertSame(4, MonitoringCheck::get()->count());
    }

    /** A reflected credential in health data is never saved, including failed attempts. */
    public function testHealthCredentialRejection(): void
    {
        $website = $this->website();
        $this->respondWith([
            'schema_version' => 2, 'inventory' => MonitoringInventoryTest::inventory(),
            'health_checks' => [HealthReport::singleton()->result('test', 'Test', 'error', self::TOKEN)],
        ]);
        $check = MonitoringCheckService::create()->check($website);
        self::assertSame('failed', $check->Status);
        self::assertEmpty($check->HealthResults);
        self::assertSame(0, MonitoringSnapshot::get()->count());
    }

    /** Meaningful modifications and a return to an old inventory each create snapshots. */
    private function assertChangesAndReversion(MonitoredWebsite $website, array $inventory): void
    {
        $inventory['composer_packages'][] = ['name' => 'vendor/new-package', 'version' => 'dev-main'];
        $this->respondWith($inventory);
        DBDatetime::set_mock_now('2026-10-26 10:02:00');
        self::assertSame(MonitoringStatus::CHANGED, MonitoringCheckService::create()->check($website)->Status);
        $this->respondWith(MonitoringInventoryTest::inventory());
        DBDatetime::set_mock_now('2026-10-26 10:03:00');
        $check = MonitoringCheckService::create()->check($website);
        self::assertSame(MonitoringStatus::CHANGED, $check->Status);
        self::assertSame(3, MonitoringSnapshot::get()->count());
        $this->assertSiteState($website, '2026-10-26 10:03:00', $check->SnapshotID, 3);
    }

    /** Error text is whitelisted and the last good snapshot and success time survive. */
    private function assertFailedAttempt(MonitoredWebsite $website): void
    {
        $before = MonitoredWebsite::get()->byID($website->ID);
        $client = $this->createMock(MonitoringHttpClient::class);
        $client->method('fetch')->willThrowException(new UnexpectedValueException('Remote page echoed ' . self::TOKEN));
        Injector::inst()->registerService($client, MonitoringHttpClient::class);
        DBDatetime::set_mock_now('2026-10-26 10:04:00');
        $check = MonitoringCheckService::create()->check($website);
        $current = MonitoredWebsite::get()->byID($website->ID);
        self::assertSame(MonitoringStatus::FAILED, $check->Status);
        self::assertSame(MonitoringCheck::FETCH_FAILED, $current->LastError);
        self::assertSame($before->LatestSnapshotID, $current->LatestSnapshotID);
        self::assertSame($before->LastSuccessfulCheckAt, $current->LastSuccessfulCheckAt);
        self::assertSame('2026-10-26 10:04:00', $current->LastCheckedAt);
        self::assertSame(5, MonitoringCheck::get()->count());
        self::assertSame(3, MonitoringSnapshot::get()->count());
    }

    /** Malformed, unknown-key and reflected-credential inventories never create snapshots. */
    public function testRejectedInventoryAndUnexpectedRollback(): void
    {
        $website = $this->website();
        $this->respondWith(['secret' => self::TOKEN]);
        self::assertSame(MonitoringStatus::FAILED, MonitoringCheckService::create()->check($website)->Status);
        self::assertSame(MonitoringCheck::INVALID_INVENTORY, MonitoringCheck::get()->first()->ErrorMessage);
        self::assertSame(0, MonitoringSnapshot::get()->count());
        $inventory = MonitoringInventoryTest::inventory();
        $inventory['php_version'] = self::TOKEN;
        $this->respondWith($inventory);
        self::assertSame(MonitoringStatus::FAILED, MonitoringCheckService::create()->check($website)->Status);
        $this->assertUnexpectedRollback($website);
    }

    /** Programming errors remain exceptions and roll back state instead of faking failed checks. */
    private function assertUnexpectedRollback(MonitoredWebsite $website): void
    {
        $client = $this->createMock(MonitoringHttpClient::class);
        $client->method('fetch')->willThrowException(new RuntimeException('Synthetic programming error'));
        Injector::inst()->registerService($client, MonitoringHttpClient::class);
        try {
            MonitoringCheckService::create()->check($website);
            self::fail('Unexpected failures must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('Synthetic programming error', $exception->getMessage());
        }
        self::assertSame(0, DB::get_conn()->transactionDepth());
        self::assertSame(2, MonitoringCheck::get()->count());
        self::assertSame(0, MonitoringSnapshot::get()->count());
        $this->assertRollbackAfterHistoryWrites($website);
    }

    /** A failure saving site state also rolls back the already-written snapshot and attempt. */
    private function assertRollbackAfterHistoryWrites(MonitoredWebsite $website): void
    {
        $this->respondWith(MonitoringInventoryTest::inventory());
        MonitoredWebsite::add_extension(MonitoringFailingWriteExtension::class);
        try {
            MonitoringCheckService::create()->check($website);
            self::fail('Unexpected persistence errors must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('Synthetic website write failure', $exception->getMessage());
        } finally {
            MonitoredWebsite::remove_extension(MonitoringFailingWriteExtension::class);
        }
        self::assertSame(0, DB::get_conn()->transactionDepth());
        self::assertSame(2, MonitoringCheck::get()->count());
        self::assertSame(0, MonitoringSnapshot::get()->count());
        self::assertSame(0, $this->refreshes);
    }

    /** Write-only form replacement, encryption at rest, uniqueness and access restrictions. */
    public function testWebsiteConfigurationAndHistoryPermissions(): void
    {
        $website = $this->website();
        $this->assertCredentialPersistence($website);
        $this->assertWebsiteValidation();
        $this->respondWith(MonitoringInventoryTest::inventory());
        $check = MonitoringCheckService::create()->check($website);
        $this->assertHistoryProtection($check);
        Security::setCurrentUser(null);
        self::assertFalse($website->canView());
        self::assertFalse($website->canEdit());
        self::assertFalse($website->canCreate());
        self::assertFalse($check->canView());
        self::assertFalse($check->Snapshot()->canView());
        $this->expectException(LogicException::class);
        MonitoringCheckService::create()->check($website);
    }

    /** Null replacements through every setter path retain the persisted credential. */
    public function testNullReplacementTokenRetainsCredential(): void
    {
        $website = $this->website();
        $website->ReplacementToken = null;
        $website->write();
        self::assertSame(self::TOKEN, MonitoredWebsite::get()->byID($website->ID)->ApiToken);
        $website->setReplacementToken(null);
        $website->write();
        self::assertSame(self::TOKEN, MonitoredWebsite::get()->byID($website->ID)->ApiToken);
        $website->setField('ReplacementToken', null);
        $website->write();
        self::assertSame(self::TOKEN, MonitoredWebsite::get()->byID($website->ID)->ApiToken);
    }

    /** Loading/saving an empty password input never clears or displays the old token. */
    private function assertCredentialPersistence(MonitoredWebsite $website): void
    {
        $raw = DB::prepared_query('SELECT "ApiToken" FROM "DeveloperTools_MonitoredWebsite" WHERE "ID" = ?', [$website->ID])->value();
        self::assertNotSame(self::TOKEN, $raw);
        $loaded = MonitoredWebsite::get()->byID($website->ID);
        self::assertSame(self::TOKEN, $loaded->ApiToken);
        $fields = $loaded->getCMSFields();
        foreach (['ApiToken', 'ConfigID', 'LatestSnapshotID'] as $hidden) {
            self::assertNull($fields->dataFieldByName($hidden));
        }
        $field = $fields->dataFieldByName('ReplacementToken');
        self::assertInstanceOf(PasswordField::class, $field);
        self::assertEmpty($field->getValue());
        $field->setValue('')->saveInto($loaded);
        $loaded->Name = 'Renamed';
        $loaded->write();
        self::assertSame(self::TOKEN, MonitoredWebsite::get()->byID($website->ID)->ApiToken);
        $field->setValue('synthetic-replacement-credential')->saveInto($loaded);
        $loaded->write();
        self::assertSame('synthetic-replacement-credential', MonitoredWebsite::get()->byID($website->ID)->ApiToken);
    }

    /** Empty initial credentials and equivalent normalized URLs cannot be saved. */
    private function assertWebsiteValidation(): void
    {
        $duplicate = MonitoredWebsite::create([
            'Name' => 'Duplicate', 'BaseURL' => 'HTTPS://CUSTOMER.ORG:443/',
            'ApiToken' => self::TOKEN,
        ]);
        self::assertFalse($duplicate->validate()->isValid());
        try {
            $duplicate->write(skipValidation: true);
            self::fail('The normalized URL must also have a database uniqueness constraint.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('BaseURL', $exception->getMessage());
        }

        $missing = MonitoredWebsite::create(['BaseURL' => 'https://another.org']);
        self::assertFalse($missing->validate()->isValid());
        self::assertFalse($duplicate->canDelete());
    }

    /** CMS and direct ORM updates cannot alter persisted history. */
    private function assertHistoryProtection(MonitoringCheck $check): void
    {
        self::assertFalse($check->canCreate());
        self::assertFalse($check->canEdit());
        self::assertFalse($check->canDelete());
        $snapshot = $check->Snapshot();
        self::assertFalse($snapshot->canCreate());
        self::assertFalse($snapshot->canEdit());
        self::assertFalse($snapshot->canDelete());
        self::assertStringContainsString('<table', $snapshot->getCMSFields()->fieldByName('Root.Main.Inventory')->getContent());
        $this->assertImmutable($snapshot, 'ObservedAt', '2026-01-01 00:00:00');
        $this->assertImmutable($check, 'FinishedAt', '2026-01-01 00:00:00');
    }

    /** Valid changes still fail the immutable write hook. */
    private function assertImmutable(MonitoringSnapshot|MonitoringCheck $record, string $field, string $value): void
    {
        $record->setField($field, $value);
        try {
            $record->write();
            self::fail('History must not be rewritten.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }
    }

    /** Ignores stale in-memory credentials and inventory pointers. */
    public function testFreshLockedStateAndScheduledFailuresContinue(): void
    {
        $stale = $this->website();
        $current = MonitoredWebsite::get()->byID($stale->ID);
        $current->setReplacementToken('synthetic-current-credential');
        $current->write();
        $client = $this->createMock(MonitoringHttpClient::class);
        $client->expects($this->once())->method('fetch')->with('https://customer.org', 'synthetic-current-credential')
            ->willReturn(json_encode(MonitoringInventoryTest::inventory()));
        Injector::inst()->registerService($client, MonitoringHttpClient::class);
        self::assertSame(MonitoringStatus::CHANGED, MonitoringCheckService::create()->check($stale)->Status);
        $this->assertBatchContinues();
    }

    /** One expected failure does not prevent checks of other active sites. */
    private function assertBatchContinues(): void
    {
        $second = $this->website('https://second.org');
        $disabled = $this->website('https://disabled.org');
        $disabled->Active = false;
        $disabled->write();
        $client = $this->createMock(MonitoringHttpClient::class);
        $client->expects($this->exactly(2))->method('fetch')->willReturnCallback(
            static function (string $url, string $token): string {
                if ($url === 'https://customer.org') {
                    throw new UnexpectedValueException('Synthetic timeout');
                }
                return json_encode(MonitoringInventoryTest::inventory());
            }
        );
        Injector::inst()->registerService($client, MonitoringHttpClient::class);
        $notification = $this->createMock(MonitoringNotificationService::class);
        $notification->expects($this->once())->method('send')->willReturnCallback(
            static function (array $outcomes): void {
                self::assertCount(3, $outcomes);
                self::assertSame(1, count(array_filter($outcomes, static fn(array $row): bool => $row['check'] === null)));
                $statuses = array_map(static fn(array $row): ?string => $row['check']?->Status, $outcomes);
                self::assertContains('failed', $statuses);
                self::assertContains('changed', $statuses);
            }
        );
        Injector::inst()->registerService($notification, MonitoringNotificationService::class);
        self::assertFalse(MonitoringCheckService::create()->runAll(new BufferedOutput()));
        self::assertSame(1, $second->Checks()->count());
        self::assertSame(0, $disabled->Checks()->count());
    }

    /** A second database session cannot check the same site while its request is in flight. */
    public function testDatabaseRowLockSpansRequestAndReleasesAfterCommit(): void
    {
        $website = $this->website();
        $unrelated = $this->website('https://unrelated.org');
        $config = DB::getConfig(DB::CONN_PRIMARY);
        $config['database'] = DB::get_conn()->getSelectedDatabase();
        $other = new MySQLDatabase();
        $other->setConnector(new MySQLiConnector());
        $other->setSchemaManager(new MySQLSchemaManager());
        $other->setQueryBuilder(new MySQLQueryBuilder());
        $other->connect($config);
        $other->query('SET SESSION innodb_lock_wait_timeout = 1');
        $client = $this->createMock(MonitoringHttpClient::class);
        $client->method('fetch')->willReturnCallback(function () use ($other, $website, $unrelated): string {
            $this->assertCompetingSessionBlocked($other, $website, $unrelated);
            return json_encode(MonitoringInventoryTest::inventory());
        });
        Injector::inst()->registerService($client, MonitoringHttpClient::class);
        MonitoringCheckService::create()->check($website);
        self::assertNotEmpty($other->preparedQuery('SELECT "ID" FROM "DeveloperTools_MonitoredWebsite" WHERE "ID" = ? FOR UPDATE', [$website->ID])->record());
    }

    /** The lock is per website, not process-local or a global table lock. */
    private function assertCompetingSessionBlocked(MySQLDatabase $other, MonitoredWebsite $website, MonitoredWebsite $unrelated): void
    {
        $other->transactionStart();
        try {
            self::assertNotEmpty($other->preparedQuery('SELECT "ID" FROM "DeveloperTools_MonitoredWebsite" WHERE "ID" = ? FOR UPDATE', [$unrelated->ID])->record());
            $other->preparedQuery('SELECT "ID" FROM "DeveloperTools_MonitoredWebsite" WHERE "ID" = ? FOR UPDATE', [$website->ID]);
            self::fail('A competing session must be blocked by the website row lock.');
        } catch (DatabaseException $exception) {
            self::assertStringContainsString('Lock wait timeout', $exception->getMessage());
        } finally {
            $other->transactionRollback();
        }
    }

    /** An unsupported driver is rejected before any request or inventory mutation. */
    public function testUnsupportedDriverAndAmbientTransaction(): void
    {
        $website = $this->website();
        $connection = DB::get_conn(DB::CONN_PRIMARY);
        DB::set_conn(new NullDatabase(), DB::CONN_PRIMARY);
        try {
            (new ReflectionMethod(MonitoringCheckService::class, 'connection'))->invoke(MonitoringCheckService::create());
            self::fail('An unsupported database must fail explicitly.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('MySQL/MariaDB', $exception->getMessage());
        } finally {
            DB::set_conn($connection, DB::CONN_PRIMARY);
        }
        $this->assertAmbientTransactionRejected($website);
    }

    /** A nested pseudo-commit cannot trigger provider refresh before real persistence. */
    private function assertAmbientTransactionRejected(MonitoredWebsite $website): void
    {
        $connection = DB::get_conn();
        $connection->transactionStart();
        try {
            MonitoringCheckService::create()->check($website);
            self::fail('Ambient transactions must not be accepted.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('top-level transaction', $exception->getMessage());
        } finally {
            $connection->transactionRollback();
        }
    }

    /** Task CLI authorization uses the configured account, not an ambient administrator. */
    public function testTaskRequiresConfiguredAdministrator(): void
    {
        $admin = Security::getCurrentUser();
        $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: new BufferedOutput());
        $task = MonitoringCheckTask::create();
        Config::modify()->set(MonitoringCheckTask::class, 'cli_user_email', null);
        self::assertSame(Command::FAILURE, $task->run(new ArrayInput([]), $output));
        self::assertSame($admin->ID, Security::getCurrentUser()->ID);
        Config::modify()->set(MonitoringCheckTask::class, 'cli_user_email', $admin->Email);
        self::assertSame(Command::SUCCESS, $task->run(new ArrayInput([]), $output));
        self::assertSame('tasks:developertools-monitoring-check', MonitoringCheckTask::getName());
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        Config::modify()->set(MonitoringCheckTask::class, 'cli_user_email', Security::getCurrentUser()->Email);
        self::assertSame(Command::FAILURE, $task->run(new ArrayInput([]), $output));
    }

    /** Supplies a saved site without contacting its hostname. */
    private function website(string $url = 'https://customer.org'): MonitoredWebsite
    {
        $website = MonitoredWebsite::create([
            'Name' => 'Test site', 'BaseURL' => $url, 'ApiToken' => self::TOKEN,
        ]);
        $website->write();
        return $website;
    }

    /** Registers the only customer HTTP implementation used by a test. */
    private function respondWith(array $inventory): void
    {
        $client = $this->createMock(MonitoringHttpClient::class);
        $client->method('fetch')->willReturn(json_encode($inventory, JSON_THROW_ON_ERROR));
        Injector::inst()->registerService($client, MonitoringHttpClient::class);
    }

    /** Reads fresh state to avoid accidentally asserting against the caller's stale instance. */
    private function assertSiteState(MonitoredWebsite $website, string $time, int $snapshotID, int $count): void
    {
        $current = MonitoredWebsite::get()->byID($website->ID);
        self::assertSame('2026-10-26 10:00:00', $current->FirstCheckedAt);
        self::assertSame($time, $current->LastCheckedAt);
        self::assertSame($time, $current->LastSuccessfulCheckAt);
        self::assertSame($snapshotID, $current->LatestSnapshotID);
        self::assertSame($time, $current->getLastChangeAt());
        self::assertSame($count, MonitoringSnapshot::get()->count());
    }
}

/**
 * Injects a late persistence failure without replacing the transaction or HTTP path.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class MonitoringFailingWriteExtension extends Extension implements TestOnly
{
    /** Simulates an unexpected extension/programming failure after history creation. */
    public function onBeforeWrite(): void
    {
        throw new RuntimeException('Synthetic website write failure');
    }
}

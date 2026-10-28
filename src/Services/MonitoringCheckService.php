<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Constants\MonitoringField as F;
use Clesson\Silverstripe\DeveloperTools\Constants\MonitoringStatus;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoredWebsite;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoringCheck;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoringSnapshot;
use DomainException;
use LogicException;
use RuntimeException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\Connect\MySQLDatabase;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Permission;
use Symfony\Component\Console\Output\OutputInterface;
use UnexpectedValueException;

/**
 * Serializes inventory checks against the primary MySQL/MariaDB InnoDB database.
 *
 * All workers must use the same writable database (no replica routing). A row
 * lock spans the bounded HTTP request and atomic history/state persistence.
 * Existing transactions are rejected: provider refresh must run after COMMIT,
 * not after a virtual nested commit. Unexpected errors roll back and propagate.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class MonitoringCheckService
{
    use Injectable;

    /** Checks one saved site using only its current locked database configuration. */
    public function check(MonitoredWebsite $website): MonitoringCheck
    {
        $this->requireAdministrator();
        if (!$website->isInDB()) {
            throw new LogicException('Only persisted websites can be checked.');
        }
        DB::setMustUsePrimary();
        $connection = $this->connection();
        $check = $this->boundedTransaction($connection, (int) $website->ID);
        if ($check->Status !== MonitoringStatus::FAILED) {
            ReleaseVersionService::singleton()->refresh($check->Snapshot()->getInventory());
        }
        return $check;
    }

    /** Runs enabled sites independently; expected failures remain ordinary check records. */
    public function runAll(OutputInterface $output): bool
    {
        $this->requireAdministrator();
        $outcomes = [];
        foreach (MonitoredWebsite::get() as $website) {
            $check = $website->Active ? $this->check($website) : null;
            $outcomes[] = ['website' => $website, 'check' => $check];
            if ($check) {
                $output->writeln('#' . $website->ID . ': ' . MonitoringStatus::label($check->Status));
            }
        }
        return $this->finishRun($outcomes, $output);
    }

    /** Emits exactly one summary after all completed attempts and outside their transactions. */
    private function finishRun(array $outcomes, OutputInterface $output): bool
    {
        $checks = array_filter(array_column($outcomes, 'check'));
        $failed = count(array_filter($checks, static fn(MonitoringCheck $check): bool => $check->Status === MonitoringStatus::FAILED));
        $output->writeln(_t(__CLASS__ . '.SUMMARY', '{total} websites checked; {failed} failed.', [
            'total' => count($checks), 'failed' => $failed,
        ]));
        MonitoringNotificationService::singleton()->send($outcomes);
        return $failed === 0;
    }

    /** Keeps service entry points ADMIN-only independently of task/controller guards. */
    private function requireAdministrator(): void
    {
        if (!Permission::check('ADMIN')) {
            throw new LogicException('Monitoring checks require ADMIN permission.');
        }
    }

    /** Fails explicitly on unsupported engines or an ambient transaction. */
    private function connection(): MySQLDatabase
    {
        $connection = DB::get_conn(DB::CONN_PRIMARY);
        if (!$connection instanceof MySQLDatabase || !$connection->supportsTransactions()) {
            throw new RuntimeException('Monitoring requires MySQL/MariaDB with InnoDB row locks.');
        }
        if ($connection->transactionDepth() !== 0) {
            throw new LogicException('Monitoring checks require their own top-level transaction.');
        }
        $this->requireInnoDB();
        return $connection;
    }

    /** Verifies transactional persistence for every table modified atomically. */
    private function requireInnoDB(): void
    {
        foreach ([MonitoredWebsite::class, MonitoringSnapshot::class, MonitoringCheck::class] as $class) {
            $table = MonitoredWebsite::getSchema()->tableName($class);
            $engine = DB::prepared_query(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            )->value();
            if (strtolower((string) $engine) !== 'innodb') {
                throw new RuntimeException('Monitoring requires InnoDB tables.');
            }
        }
    }

    /** Bounds contention without changing the connection's lock policy permanently. */
    private function boundedTransaction(MySQLDatabase $connection, int $websiteID): MonitoringCheck
    {
        $timeout = (int) $connection->query('SELECT @@SESSION.innodb_lock_wait_timeout')->value();
        $connection->query('SET SESSION innodb_lock_wait_timeout = 5');
        try {
            return $this->transaction($connection, $websiteID);
        } finally {
            $connection->query('SET SESSION innodb_lock_wait_timeout = ' . $timeout);
        }
    }

    /** Rolls back every unexpected exception without converting programming errors to outcomes. */
    private function transaction(MySQLDatabase $connection, int $websiteID): MonitoringCheck
    {
        $committed = false;
        $connection->transactionStart();
        try {
            $website = $this->lockWebsite($websiteID);
            $check = $this->attempt($website);
            $connection->transactionEnd();
            $committed = true;
            return $check;
        } finally {
            if (!$committed) {
                $connection->transactionRollback();
            }
        }
    }

    /** Uses a locking/current read rather than stale ORM instances or repeatable-read snapshots. */
    private function lockWebsite(int $websiteID): MonitoredWebsite
    {
        $table = MonitoredWebsite::getSchema()->tableName(MonitoredWebsite::class);
        $row = DB::prepared_query('SELECT * FROM "' . $table . '" WHERE "ID" = ? FOR UPDATE', [$websiteID])->record();
        if (!$row) {
            throw new LogicException('The monitored website no longer exists.');
        }
        return MonitoredWebsite::create($row);
    }

    /** Records timestamps for all expected fetch/validation outcomes. */
    private function attempt(MonitoredWebsite $website): MonitoringCheck
    {
        $check = $this->newAttempt($website);
        try {
            $snapshot = $this->fetchSnapshot($website, $check);
            $check->SnapshotID = $snapshot->ID;
            $check->Status = (int) $snapshot->ID === (int) $website->LatestSnapshotID
                ? MonitoringStatus::UNCHANGED : MonitoringStatus::CHANGED;
        } catch (UnexpectedValueException $exception) {
            $check->Status = MonitoringStatus::FAILED;
            $check->ErrorMessage = MonitoringCheck::FETCH_FAILED;
        } catch (DomainException $exception) {
            $check->Status = MonitoringStatus::FAILED;
            $check->ErrorMessage = MonitoringCheck::INVALID_INVENTORY;
        }
        return $this->persistAttempt($website, $check);
    }

    /** Retains the last valid inventory reference even for a failed attempt. */
    private function newAttempt(MonitoredWebsite $website): MonitoringCheck
    {
        return MonitoringCheck::create([
            F::WEBSITE . 'ID' => $website->ID,
            F::SNAPSHOT . 'ID' => $website->LatestSnapshotID,
            F::STARTED_AT => DBDatetime::now()->getValue(),
        ]);
    }

    /** Creates a snapshot only when the latest canonical inventory differs. */
    private function fetchSnapshot(MonitoredWebsite $website, MonitoringCheck $check): MonitoringSnapshot
    {
        $token = (string) $website->ApiToken;
        $body = MonitoringHttpClient::singleton()->fetch((string) $website->BaseURL, $token);
        $response = MonitoringResponse::singleton()->decode($body);
        if ($this->containsCredential($response, $token)) {
            throw new DomainException('Inventory contains the request credential.');
        }
        $check->HealthResults = json_encode($response['health_checks'], JSON_THROW_ON_ERROR);
        return $this->snapshotForInventory($website, $response['inventory']);
    }

    /** Health outcomes never participate in inventory equality. */
    private function snapshotForInventory(MonitoredWebsite $website, array $inventory): MonitoringSnapshot
    {
        $payload = MonitoringInventory::singleton()->encode($inventory);
        $latest = $website->LatestSnapshot();
        if ($latest->exists() && $latest->Payload === $payload) {
            return $latest;
        }
        return $this->createSnapshot($website, $payload);
    }

    /** Compares decoded values so JSON escaping cannot conceal a reflected credential. */
    private function containsCredential(array $response, string $token): bool
    {
        $found = false;
        array_walk_recursive($response, static function (mixed $value) use ($token, &$found): void {
            if (is_string($value) && $token !== '' && str_contains($value, $token)) {
                $found = true;
            }
        });
        return $found;
    }

    /** Persists immutable canonical data before assigning the website pointer. */
    private function createSnapshot(MonitoredWebsite $website, string $payload): MonitoringSnapshot
    {
        $snapshot = MonitoringSnapshot::create([
            F::WEBSITE . 'ID' => $website->ID,
            F::OBSERVED_AT => DBDatetime::now()->getValue(),
            F::PAYLOAD => $payload,
        ]);
        $snapshot->write();
        return $snapshot;
    }

    /** Atomically saves state without losing a valid snapshot on failure. */
    private function persistAttempt(MonitoredWebsite $website, MonitoringCheck $check): MonitoringCheck
    {
        if ($check->Status === MonitoringStatus::FAILED) {
            $check->HealthResults = '';
        }
        $check->FinishedAt = DBDatetime::now()->getValue();
        $check->write();
        $website->FirstCheckedAt = $website->FirstCheckedAt ?: $check->StartedAt;
        $website->LastCheckedAt = $check->StartedAt;
        $website->LastStatus = $check->Status;
        $website->LastError = $check->ErrorMessage;
        if ($check->Status !== MonitoringStatus::FAILED) {
            $website->LastSuccessfulCheckAt = $check->FinishedAt;
            $website->LatestSnapshotID = $check->SnapshotID;
        }
        $website->write();
        return $check;
    }
}

<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tasks;

use Clesson\Silverstripe\DeveloperTools\Services\MonitoringCheckService;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Explicitly scheduled or manually invoked polling; never creates a schedule.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tasks
 */
class MonitoringCheckTask extends BuildTask
{
    /** Stable sake/scheduler command identifier. */
    protected static string $commandName = 'developertools-monitoring-check';
    /** @config */
    private static ?string $cli_user_email = null;
    /** Task-list fallback, translated by getTitle(). */
    protected string $title = 'Check monitored websites';
    /** Task-list description. */
    protected static string $description = 'Checks enabled customer websites and records inventory changes.';

    /** Localizes the visible task title. */
    public function getTitle(): string
    {
        return _t(__CLASS__ . '.TITLE', 'Check monitored websites');
    }

    /** Uses the module's uppercase translation-key convention. */
    public static function getDescription(): string
    {
        return _t(__CLASS__ . '.DESCRIPTION', 'Checks enabled customer websites and records inventory changes.');
    }

    /** Requires a real ADMIN context, delegates all business logic and restores the caller. */
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $previous = Security::getCurrentUser();
        try {
            if (!$this->establishContext() || !Permission::check('ADMIN')) {
                $output->writeln(_t(__CLASS__ . '.ADMIN_REQUIRED', 'Configure cli_user_email for an administrator; ADMIN permission is required.'));
                return Command::FAILURE;
            }
            $service = Injector::inst()->create(MonitoringCheckService::class);
            return $service->runAll($output) ? Command::SUCCESS : Command::FAILURE;
        } finally {
            Security::setCurrentUser($previous);
        }
    }

    /** CLI invocations must not borrow an unrelated ambient user. */
    private function establishContext(): bool
    {
        if (PHP_SAPI !== 'cli') {
            return Security::getCurrentUser() !== null;
        }
        $email = static::config()->get('cli_user_email');
        $member = $email ? Member::get()->find('Email', $email) : null;
        Security::setCurrentUser($member);
        return $member !== null;
    }
}

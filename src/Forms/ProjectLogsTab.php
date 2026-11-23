<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Forms;

use Clesson\Silverstripe\DeveloperTools\Admins\DeveloperToolsAdmin;
use Clesson\Silverstripe\DeveloperTools\Services\ProjectLogReader;
use RuntimeException;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\Tab;
use SilverStripe\Model\ArrayData;

class ProjectLogsTab
{
    public function build(): Tab
    {
        $files = [];
        $error = '';
        try {
            foreach (ProjectLogReader::singleton()->files() as $name) {
                $files[] = [
                    'Name' => $name,
                    'URL' => DeveloperToolsAdmin::singleton()->Link('logContent') . '?file=' . rawurlencode($name),
                ];
            }
        } catch (RuntimeException $exception) {
            $error = _t(__CLASS__ . '.DIRECTORY_ERROR', 'The project logs directory is missing or unreadable.');
        }
        $html = ArrayData::create([
            'Files' => $files,
            'Error' => $error,
            'ResetURL' => DeveloperToolsAdmin::singleton()->Link('resetLogContent'),
            'Empty' => _t(__CLASS__ . '.EMPTY', 'No .log files found.'),
            'Interval' => _t(__CLASS__ . '.INTERVAL', 'Refresh interval in seconds (5–360)'),
            'Enable' => _t(__CLASS__ . '.ENABLE', 'Enable automatic refresh'),
            'InvalidInterval' => _t(__CLASS__ . '.INVALID_INTERVAL', 'Enter a whole number between 5 and 360.'),
            'FrameTitle' => _t(__CLASS__ . '.FRAME_TITLE', 'Log contents'),
            'ResetLabel' => _t(__CLASS__ . '.RESET', 'Reset file'),
            'ResetConfirm' => _t(__CLASS__ . '.RESET_CONFIRM',
                'Do you really want to reset {file}? This cannot be undone.'),
            'ResetSuccess' => _t(__CLASS__ . '.RESET_SUCCESS', 'The log file has been reset.'),
            'ResetError' => _t(__CLASS__ . '.RESET_ERROR', 'The log file could not be reset.'),
        ])->renderWith('Clesson/Silverstripe/DeveloperTools/ProjectLogs');

        return Tab::create('Logs', _t(__CLASS__ . '.TITLE', 'Logs'), LiteralField::create('ProjectLogs', (string) $html));
    }
}

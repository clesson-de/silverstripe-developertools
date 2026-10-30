<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tests;

use Clesson\Silverstripe\DeveloperTools\Constants\ToastField as F;
use Clesson\Silverstripe\DeveloperTools\Forms\GridFieldConfig_ToastMessages;
use Clesson\Silverstripe\DeveloperTools\Forms\GridField\GridFieldAction_ToggleActive;
use Clesson\Silverstripe\DeveloperTools\Models\DeveloperToolsConfig;
use Clesson\Silverstripe\DeveloperTools\Models\ToastMessage;
use Clesson\Silverstripe\Markdown\FieldTypes\DBMarkdown;
use Clesson\Silverstripe\Markdown\Forms\MarkdownEditorField;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Security\Group;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldLazyLoader;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Toast persistence, editor fields, defaults, validation and CMS authorization.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Tests
 */
class ToastMessageTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testDefaultsAndPersistence(): void
    {
        $toast = ToastMessage::create();
        $this->logOut();
        self::assertSame('info', $toast->Type);
        self::assertSame('all', $toast->Environment);
        self::assertSame(5, $toast->AutoHideSeconds);
        self::assertTrue((bool) $toast->Removable);
        self::assertTrue((bool) $toast->Active);
        self::assertSame('right', $toast->HorizontalPosition);
        self::assertSame('top', $toast->VerticalPosition);
        self::assertFalse($toast->validate()->isValid());
        $toast->Message = '**Notice** with [link](https://example.com)';
        $toast->AutoHideSeconds = 0;
        $toast->SettingsID = DeveloperToolsConfig::currentRecord()->ID;
        $toast->write();
        $saved = ToastMessage::get()->byID($toast->ID);
        self::assertInstanceOf(DBMarkdown::class, $saved->dbObject(F::MESSAGE));
        self::assertSame('**Notice** with [link](https://example.com)', $saved->dbObject(F::MESSAGE)->getMarkdown());
        self::assertStringContainsString('<strong>Notice</strong>', $saved->dbObject(F::MESSAGE)->getHTML());
        self::assertSame('Notice with link', $saved->Preview);
        self::assertSame($toast->ID, $saved->Settings()->ToastMessages()->first()->ID);
        self::assertSame(0, $saved->AutoHideSeconds);
        self::assertTrue($saved->validate()->isValid());
        self::assertFalse($saved->dbObject(F::TITLE)->exists());
        $saved->Title = '<b>Plain</b> **title**';
        $saved->write();
        self::assertSame('<b>Plain</b> **title**', ToastMessage::get()->byID($toast->ID)->Title);
    }

    public function testCMSFieldsAndPermissions(): void
    {
        $toast = ToastMessage::create();
        $this->logOut();
        foreach (['canView', 'canEdit', 'canCreate', 'canDelete'] as $method) {
            self::assertFalse($toast->$method());
        }
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        self::assertFalse($toast->canView());
        $this->logInWithPermission('ADMIN');
        foreach (['canView', 'canEdit', 'canCreate', 'canDelete'] as $method) {
            self::assertTrue($toast->$method());
        }
        $fields = $toast->getCMSFields();
        self::assertInstanceOf(TextField::class, $fields->dataFieldByName(F::TITLE));
        self::assertInstanceOf(ListboxField::class, $fields->dataFieldByName(F::GROUPS));
        self::assertInstanceOf(DatetimeField::class, $fields->dataFieldByName(F::START_DATE));
        self::assertInstanceOf(DatetimeField::class, $fields->dataFieldByName(F::END_DATE));
        self::assertSame(['Main', 'Appearance', 'Filters'], $fields->fieldByName('Root')->getChildren()->column('Name'));
        self::assertSame([F::TITLE, F::MESSAGE, F::ACTIVE], $fields->fieldByName('Root.Main')->getChildren()->column('Name'));
        self::assertSame([F::TYPE, F::POSITION, F::AUTO_HIDE_SECONDS, F::REMOVABLE],
            $fields->fieldByName('Root.Appearance')->getChildren()->column('Name'));
        self::assertSame([F::ENVIRONMENT, F::IP_RANGES, F::GROUPS, F::START_DATE, F::END_DATE],
            $fields->fieldByName('Root.Filters')->getChildren()->column('Name'));
        $position = $fields->fieldByName('Root.Appearance.' . F::POSITION);
        self::assertInstanceOf(FieldGroup::class, $position);
        self::assertSame([F::HORIZONTAL_POSITION, F::VERTICAL_POSITION], $position->getChildren()->column('Name'));
        self::assertStringContainsString('close button', $fields->dataFieldByName(F::REMOVABLE)->Title());
        self::assertInstanceOf(MarkdownEditorField::class, $fields->dataFieldByName(F::MESSAGE));
        self::assertNull($fields->dataFieldByName('SettingsID'));
        self::assertSame(['info', 'warning', 'alert'], array_keys($fields->dataFieldByName(F::TYPE)->getSource()));
        self::assertStringContainsString('0', $fields->dataFieldByName(F::AUTO_HIDE_SECONDS)->getDescription());
        $config = GridFieldConfig_ToastMessages::create();
        self::assertNotNull($config->getComponentByType(GridFieldDeleteAction::class));
        self::assertNotNull($config->getComponentByType(GridFieldLazyLoader::class));
    }

    public function testGridRenderingAndScopedToggle(): void
    {
        $this->logInWithPermission('ADMIN');
        $settings = DeveloperToolsConfig::currentRecord();
        $toast = ToastMessage::create(['Message' => '**Grid notice**', 'SettingsID' => $settings->ID]);
        $toast->write();
        $config = GridFieldConfig_ToastMessages::create();
        $grid = GridField::create('ToastMessages', 'Toasts', $settings->ToastMessages(), $config);
        Config::modify()->set(Controller::class, 'url_segment', 'toast-test');
        $form = Form::create(Controller::create(), 'ToastTestForm', FieldList::create($grid), FieldList::create());
        $html = (string) $form->forTemplate();
        self::assertStringContainsString('Grid notice', $html);
        self::assertStringContainsString('text-bg-info">Dev</span>', $html);
        self::assertStringContainsString('text-bg-success">Live</span>', $html);
        self::assertStringContainsString('col-MonitoringActive', $html);
        self::assertStringContainsString('col-action_SetOrderRecordDates', $html);
        self::assertStringContainsString('SetOrderRecordDates', $html);
        self::assertStringNotContainsString('col-Created', $html);
        self::assertStringNotContainsString('col-LastEdited', $html);
        self::assertLessThan(strpos($html, 'col-action_SetOrderRecordDates'), strpos($html, 'col-MonitoringActive'));
        self::assertStringContainsString('font-icon-tick', $html);
        self::assertStringContainsString('aria-pressed="true"', $html);
        self::assertStringContainsString('col-Position', $html);
        self::assertStringNotContainsString('col-Title', $html);
        self::assertStringContainsString('col-Preview', $html);
        self::assertStringNotContainsString('col-Type', $html);
        self::assertStringNotContainsString('col-AutoHideSeconds', $html);
        self::assertStringNotContainsString('col-HorizontalPosition', $html);
        self::assertStringNotContainsString('col-VerticalPosition', $html);
        self::assertStringNotContainsString('col-Active"', $html);
        $toggle = $config->getComponentByType(GridFieldAction_ToggleActive::class);
        self::assertSame(['title' => 'Active'], $toggle->getColumnMetadata($grid, 'MonitoringActive'));
        $toggle->handleAction($grid, 'togglemonitoringactive', ['RecordID' => $toast->ID], []);
        self::assertFalse((bool) ToastMessage::get()->byID($toast->ID)->Active);
        $inactiveHTML = $toggle->getColumnContent($grid, ToastMessage::get()->byID($toast->ID), 'MonitoringActive');
        self::assertStringContainsString('font-icon-cancel', $inactiveHTML);
        self::assertStringContainsString('aria-pressed="false"', $inactiveHTML);
        self::assertStringContainsString('aria-label="Disabled"', $inactiveHTML);
        $this->logOut();
        $this->expectException(HTTPResponse_Exception::class);
        $toggle->handleAction($grid, 'togglemonitoringactive', ['RecordID' => $toast->ID], []);
    }

    /** Sort state works on a fresh grid, even before its header has been rendered. */
    public function testRecordDatesSortByLastEdited(): void
    {
        $settings = DeveloperToolsConfig::currentRecord();
        $first = ToastMessage::create(['Message' => 'First', 'SettingsID' => $settings->ID]);
        $first->write();
        $second = ToastMessage::create(['Message' => 'Second', 'SettingsID' => $settings->ID]);
        $second->write();
        $first->Created = '2026-10-28 10:00:00';
        DBDatetime::withFixedNow('2026-10-29 12:00:00', fn() => $first->write());
        $second->Created = '2026-10-29 10:00:00';
        DBDatetime::withFixedNow('2026-10-29 11:00:00', fn() => $second->write());
        foreach (['sortasc' => [$second->ID, $first->ID], 'sortdesc' => [$first->ID, $second->ID]] as $action => $expected) {
            $config = GridFieldConfig_ToastMessages::create();
            $grid = GridField::create('ToastMessages', 'Overlays', $settings->ToastMessages(), $config);
            $header = $config->getComponentByType(GridFieldSortableHeader::class);
            self::assertSame('LastEdited', $header->getFieldSorting()['RecordDates']);
            $header->handleAction($grid, $action, ['SortColumn' => 'LastEdited'], []);
            self::assertSame($expected, array_map('intval', $grid->getManipulatedList()->column('ID')));
        }
    }

    #[DataProvider('environmentBadgeProvider')]
    public function testEnvironmentColumnBadges(string $environment, array $labels): void
    {
        $this->logInWithPermission('ADMIN');
        $settings = DeveloperToolsConfig::currentRecord();
        $toast = ToastMessage::create(['Message' => 'Notice', 'Environment' => $environment, 'SettingsID' => $settings->ID]);
        $toast->write();
        $config = GridFieldConfig_ToastMessages::create();
        $grid = GridField::create('ToastMessages', 'Toasts', $settings->ToastMessages(), $config);
        $html = (string) $config->getComponentByType(GridFieldDataColumns::class)->getColumnContent($grid, $toast, F::ENVIRONMENT);
        preg_match_all('/<span class="badge [^"]+">([^<]+)<\/span>/', $html, $matches);
        self::assertSame($labels, $matches[1]);
        self::assertSame(count($labels), substr_count($html, '<span '));
    }

    public static function environmentBadgeProvider(): array
    {
        return [['dev', ['Dev']], ['live', ['Live']], ['all', ['Dev', 'Live']]];
    }

    #[DataProvider('positionProvider')]
    public function testPositionColumnHighlightsExactlyOneCell(string $vertical, string $horizontal, int $index): void
    {
        $config = GridFieldConfig_ToastMessages::create();
        $toast = ToastMessage::create(['VerticalPosition' => $vertical, 'HorizontalPosition' => $horizontal]);
        $grid = GridField::create('ToastMessages', 'Toasts', ToastMessage::get(), $config);
        $html = (string) $config->getComponentByType(GridFieldDataColumns::class)->getColumnContent($grid, $toast, F::POSITION);
        preg_match_all('/class="toast-position__cell([^"]*)"/', $html, $matches);
        self::assertCount(9, $matches[1]);
        self::assertSame([ $index => ' toast-position__cell--active' ], array_filter($matches[1]));
        self::assertStringContainsString('role="img"', $html);
        self::assertStringContainsString('aria-label="', $html);
        self::assertStringContainsString('title="', $html);
    }

    public static function positionProvider(): array
    {
        $positions = [];
        foreach (['top', 'center', 'bottom'] as $row => $vertical) {
            foreach (['left', 'center', 'right'] as $column => $horizontal) {
                $positions[] = [$vertical, $horizontal, $row * 3 + $column];
            }
        }
        return $positions;
    }

    #[DataProvider('messagePreviewProvider')]
    public function testCombinedMessageColumn(string $type, string $title): void
    {
        $config = GridFieldConfig_ToastMessages::create();
        $message = str_repeat('Full message text ', 12);
        $toast = ToastMessage::create(['Type' => $type, 'Title' => $title, 'Message' => $message]);
        $grid = GridField::create('ToastMessages', 'Toasts', ToastMessage::get(), $config);
        $columns = $config->getComponentByType(GridFieldDataColumns::class);
        $html = (string) $columns->getColumnContent($grid, $toast, F::PREVIEW);
        self::assertSame(['title' => ''], $columns->getColumnMetadata($grid, F::PREVIEW));
        self::assertStringContainsString('class="toast-preview toast-preview--' . $type . '"', $html);
        self::assertStringContainsString(trim($message), $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertSame($title === '' ? 0 : 1, substr_count($html, '<strong class="toast-preview__title">'));
        if ($title !== '') {
            self::assertStringContainsString('&lt;b&gt;Title&lt;/b&gt; **plain**', $html);
        }
    }

    public static function messagePreviewProvider(): array
    {
        return [
            ['info', '<b>Title</b> **plain**'], ['warning', '<b>Title</b> **plain**'],
            ['alert', '<b>Title</b> **plain**'], ['info', ''],
        ];
    }

    #[DataProvider('filterIconProvider')]
    public function testFilterColumnShowsOnlyConfiguredRestrictions(array $values, bool $groups, array $icons): void
    {
        $config = GridFieldConfig_ToastMessages::create();
        $toast = ToastMessage::create($values + ['Message' => 'Filtered notice']);
        $toast->write();
        if ($groups) {
            $group = Group::create(['Title' => 'Private audience']);
            $group->write();
            $toast->Groups()->add($group);
        }
        $grid = GridField::create('ToastMessages', 'Toasts', ToastMessage::get(), $config);
        $columns = $config->getComponentByType(GridFieldDataColumns::class);
        $html = (string) $columns->getColumnContent($grid, $toast, F::FILTERS);
        self::assertSame(['title' => 'Filters'], $columns->getColumnMetadata($grid, F::FILTERS));
        preg_match_all('/font-icon-([^"]+)"/', $html, $matches);
        self::assertSame($icons, $matches[1]);
        self::assertSame(count($icons), substr_count($html, 'aria-label="'));
        self::assertSame(count($icons), substr_count($html, 'title="'));
        self::assertStringNotContainsString('<button', $html);
        self::assertStringNotContainsString('192.0.2.1', $html);
        self::assertStringNotContainsString('Private audience', $html);
    }

    public static function filterIconProvider(): array
    {
        return [
            [[], false, []],
            [['IPRanges' => '   '], false, []],
            [['IPRanges' => '192.0.2.1'], false, ['globe']],
            [[], true, ['block-group']],
            [['StartDate' => '2026-10-29 10:00:00'], false, ['clock']],
            [['EndDate' => '2026-10-29 11:00:00'], false, ['clock']],
            [['StartDate' => '2026-10-29 10:00:00', 'EndDate' => '2026-10-29 11:00:00'], false, ['clock']],
            [['IPRanges' => '192.0.2.1', 'EndDate' => '2026-10-29 11:00:00'], true, ['globe', 'block-group', 'clock']],
        ];
    }

    #[DataProvider('displayPeriodProvider')]
    public function testOptionalDisplayPeriodPersistenceAndValidation(?string $start, ?string $end, bool $valid): void
    {
        $toast = ToastMessage::create(['Message' => 'Scheduled notice', 'StartDate' => $start, 'EndDate' => $end]);
        self::assertSame($valid, $toast->validate()->isValid());
        if ($valid) {
            $toast->write();
            $saved = ToastMessage::get()->byID($toast->ID);
            self::assertSame($start, $saved->StartDate);
            self::assertSame($end, $saved->EndDate);
        }
    }

    public static function displayPeriodProvider(): array
    {
        return [
            [null, null, true],
            ['2026-10-29 10:00:00', null, true],
            [null, '2026-10-29 11:00:00', true],
            ['2026-10-29 10:00:00', '2026-10-29 11:00:00', true],
            ['2026-10-29 10:00:00', '2026-10-29 10:00:00', true],
            ['2026-10-29 11:00:00', '2026-10-29 10:00:00', false],
        ];
    }

    public function testGroupSelectionPersistsWithoutChangingMembership(): void
    {
        $this->logInWithPermission('ADMIN');
        $group = Group::create(['Title' => 'Toast audience']);
        $group->write();
        $toast = ToastMessage::create(['Message' => 'Restricted notice']);
        $toast->write();
        $fields = $toast->getCMSFields();
        $field = $fields->dataFieldByName(F::GROUPS);
        $field->setValue([$group->ID]);
        $field->saveInto($toast);
        $toast->write();
        self::assertSame([$group->ID], ToastMessage::get()->byID($toast->ID)->Groups()->column('ID'));
        self::assertSame(0, $group->Members()->count());
        $field->setValue([]);
        $field->saveInto($toast);
        self::assertFalse(ToastMessage::get()->byID($toast->ID)->Groups()->exists());
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidFields(string $field, mixed $value): void
    {
        $toast = ToastMessage::create([F::MESSAGE => 'Valid message']);
        $toast->setField($field, $value);
        self::assertFalse($toast->validate()->isValid(), $field . ': ' . var_export($value, true));
    }

    public static function invalidProvider(): array
    {
        return [
            ['Message', ' '], ['Type', 'error'], ['Environment', 'test'],
            ['HorizontalPosition', 'up'], ['VerticalPosition', 'right'],
            ['AutoHideSeconds', -1], ['AutoHideSeconds', '1.5'],
            ['AutoHideSeconds', 'invalid'], ['AutoHideSeconds', '2147483648'],
            ['IPRanges', '192.0.2.1-::1'],
        ];
    }
}

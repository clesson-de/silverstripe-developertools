<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Forms;

use Clesson\Silverstripe\DeveloperTools\Constants\ToastEnvironment;
use Clesson\Silverstripe\DeveloperTools\Constants\ToastField as F;
use Clesson\Silverstripe\DeveloperTools\Constants\ToastHorizontalPosition;
use Clesson\Silverstripe\DeveloperTools\Constants\ToastVerticalPosition;
use Clesson\Silverstripe\DeveloperTools\Forms\GridField\GridFieldAction_ToggleActive;
use Clesson\Silverstripe\DeveloperTools\Models\ToastMessage;
use Clesson\Silverstripe\Forms\GridField\GridFieldRecordDates;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldLazyLoader;
use SilverStripe\Forms\GridField\GridFieldEditButton;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Core\Convert;

/**
 * Administrator-only toast CRUD with translated choice columns.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Forms
 */
class GridFieldConfig_ToastMessages extends GridFieldConfig_RecordEditor
{
    /** Keeps standard editing/deletion and lazily loads rows inside the Toasts tab. */
    public function __construct()
    {
        parent::__construct(20);
        $this->addComponent(GridFieldLazyLoader::create());
        $this->addComponent(GridFieldRecordDates::create(), GridFieldEditButton::class);
        $this->addComponent(GridFieldAction_ToggleActive::create(GridFieldRecordDates::COLUMN, true), GridFieldEditButton::class);
        $this->getComponentByType(GridFieldDataColumns::class)->setDisplayFields($this->columns());
        $header = $this->getComponentByType(GridFieldSortableHeader::class);
        $header->setFieldSorting(array_merge($header->getFieldSorting(), [GridFieldRecordDates::COLUMN => 'LastEdited']));
    }

    /** Uses safe typed output, leaving timestamps to the combined date component. */
    private function columns(): array
    {
        $columns = [];
        foreach ([
            F::PREVIEW, F::ENVIRONMENT, F::FILTERS, F::POSITION,
        ] as $field) {
            $columns[$field] = [
                'title' => $this->columnTitle($field),
                'callback' => fn(ToastMessage $record) => $this->renderColumn($record, $field),
            ];
        }
        return $columns;
    }

    /** Keeps the combined message column heading empty. */
    private function columnTitle(string $field): string
    {
        return match ($field) {
            F::PREVIEW => '',
            default => ToastMessage::singleton()->fieldLabel($field),
        };
    }

    /** Keeps visual columns as HTML and all other values escaped as plain text. */
    private function renderColumn(ToastMessage $record, string $field): DBField
    {
        return match ($field) {
            F::PREVIEW => DBField::create_field('HTMLText', $this->messagePreview($record)),
            F::ENVIRONMENT => DBField::create_field('HTMLText', $this->environmentBadges($record)),
            F::POSITION => DBField::create_field('HTMLText', $this->positionIcon($record)),
            F::FILTERS => DBField::create_field('HTMLText', $this->filterIcons($record)),
            default => DBField::create_field('Varchar', (string) $record->$field),
        };
    }

    /** Shows only configured restrictions, with one indicator for either or both time bounds. */
    private function filterIcons(ToastMessage $record): string
    {
        $html = [];
        if (trim((string) $record->IPRanges) !== '') {
            $html[] = $this->filterIcon('globe', _t(__CLASS__ . '.IP_FILTER_SET', 'IP filter configured'));
        }
        if ($record->Groups()->exists()) {
            $html[] = $this->filterIcon('block-group', _t(__CLASS__ . '.GROUP_FILTER_SET', 'User group filter configured'));
        }
        if ($record->StartDate || $record->EndDate) {
            $html[] = $this->filterIcon('clock', _t(__CLASS__ . '.TIME_FILTER_SET', 'Time filter configured'));
        }
        return implode(' ', $html);
    }

    /** Uses existing CMS glyphs with accessible names and tooltips, not interactive buttons. */
    private function filterIcon(string $icon, string $label): string
    {
        $label = Convert::raw2att($label);
        return '<span class="toast-filter-icon font-icon-' . $icon . '" role="img" aria-label="' . $label
            . '" title="' . $label . '"></span>';
    }

    /** Combines escaped title and full plaintext content under a single severity marker. */
    private function messagePreview(ToastMessage $record): string
    {
        $type = Convert::raw2att((string) $record->Type);
        $title = $record->dbObject(F::TITLE)->exists()
            ? '<strong class="toast-preview__title">' . Convert::raw2xml((string) $record->Title) . '</strong>'
            : '';
        $text = Convert::raw2xml((string) $record->dbObject(F::MESSAGE)->getPlain());
        return '<div class="toast-preview toast-preview--' . $type . '">' . $title
            . '<div class="toast-preview__text">' . $text . '</div></div>';
    }

    /** Renders all nine anchors in row-major order with an accessible text equivalent. */
    private function positionIcon(ToastMessage $record): string
    {
        $label = Convert::raw2att(ToastVerticalPosition::label($record->VerticalPosition)
            . ' / ' . ToastHorizontalPosition::label($record->HorizontalPosition));
        $html = '<span class="toast-position" role="img" aria-label="' . $label . '" title="' . $label . '">';
        foreach (array_keys(ToastVerticalPosition::options()) as $vertical) {
            foreach (array_keys(ToastHorizontalPosition::options()) as $horizontal) {
                $active = $record->VerticalPosition === $vertical && $record->HorizontalPosition === $horizontal;
                $html .= '<span class="toast-position__cell' . ($active ? ' toast-position__cell--active' : '')
                    . '" aria-hidden="true"></span>';
            }
        }
        return $html . '</span>';
    }

    /** Shows each targeted environment separately, including both for the all selection. */
    private function environmentBadges(ToastMessage $record): string
    {
        $badges = [
            ToastEnvironment::DEV => ['info', _t(__CLASS__ . '.DEV', 'Dev')],
            ToastEnvironment::LIVE => ['success', _t(__CLASS__ . '.LIVE', 'Live')],
        ];
        $html = [];
        foreach ($badges as $environment => [$color, $label]) {
            if ($record->Environment === ToastEnvironment::ALL || $record->Environment === $environment) {
                $html[] = '<span class="badge badge-' . $color . ' text-bg-' . $color . '">'
                    . Convert::raw2xml($label) . '</span>';
            }
        }
        return implode(' ', $html);
    }

}

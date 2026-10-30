<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Models;

use Clesson\Silverstripe\DeveloperTools\Services\OfflinePageExporter;
use Page;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

/**
 * A unique, CMS-editable maintenance document exported only from the published version.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Models
 */
class OfflinePage extends Page
{
    /** @config */
    private static string $table_name = 'DeveloperTools_OfflinePage';
    /** @config */
    private static array $defaults = ['ShowInMenus' => false, 'ShowInSearch' => false, 'CanViewType' => 'Anyone'];
    /** @config */
    private static array $allowed_children = [];
    /** @config */
    private static string $cms_icon_class = 'font-icon-block';
    /** @config */
    private static string $class_description = 'Static maintenance page for offline mode';

    /** Seeds an English published document without altering existing drafts or enabling maintenance. */
    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();
        if (static::class !== self::class || $this->otherPageExists()) {
            return;
        }
        Versioned::withVersionedMode(function (): void {
            Versioned::set_stage(Versioned::DRAFT);
            $page = $this->createDefaultPage();
            $page->write();
            $page->publishSingle();
            DB::alteration_message('Created and published the default offline page.', 'created');
        });
    }

    /** Persisted starter content deliberately stays English regardless of the build user's locale. */
    private function createDefaultPage(): self
    {
        return self::create([
            'Title' => 'Website maintenance',
            'URLSegment' => 'offline',
            'Content' => '<p>Our website is temporarily unavailable while we carry out scheduled maintenance.</p>'
                . '<p>Please try again shortly. Thank you for your patience.</p>',
        ]);
    }

    /** Prevents a second draft or live offline page from being created through the CMS. */
    public function canCreate($member = null, $context = []): bool
    {
        return parent::canCreate($member, $context) && !$this->otherPageExists();
    }

    /** There can be only one maintenance document, including unpublished drafts. */
    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if ($this->otherPageExists()) {
            $result->addError(_t(__CLASS__ . '.UNIQUE', 'Only one offline page may exist.'));
        }
        if ($this->CanViewType !== 'Anyone') {
            $result->addFieldError('CanViewType', _t(__CLASS__ . '.PUBLIC_ONLY',
                'The offline page must be viewable by anyone because its static export is public.'));
        }
        return $result;
    }

    /** Checks both stages without depending on the caller's current reading stage. */
    private function otherPageExists(): bool
    {
        foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
            if (Versioned::get_by_stage(self::class, $stage)->exclude('ID', (int) $this->ID)->exists()) {
                return true;
            }
        }
        return false;
    }

    /** Offline pages must never be access-restricted, since their export is public content. */
    public function canPublish($member = null): bool
    {
        return parent::canPublish($member) && $this->CanViewType === 'Anyone';
    }

    /** Publishing regenerates the static document; saving a draft deliberately does not. */
    public function onAfterPublish($original = null): void
    {
        parent::onAfterPublish();
        OfflinePageExporter::create()->export($this);
    }

    /** Removes obsolete public output when the live document is withdrawn. */
    public function onAfterUnpublish(): void
    {
        OfflinePageExporter::create()->remove($this);
    }

    /** Cleans up exports after deletion only if the page no longer has a live version. */
    protected function onAfterDelete(): void
    {
        parent::onAfterDelete();
        if (!Versioned::get_by_stage(self::class, Versioned::LIVE)->byID($this->ID)) {
            OfflinePageExporter::create()->remove($this);
        }
    }

    /** This single-purpose document cannot contain child pages. */
    public function canAddChildren($member = null): bool
    {
        return false;
    }

    /** Keeps the standard rich-text editor available even when Page uses Elemental globally. */
    public function includeElemental(): bool
    {
        return false;
    }
}

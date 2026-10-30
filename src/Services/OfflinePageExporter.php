<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Models\OfflinePage;
use RuntimeException;
use SilverStripe\Assets\Storage\GeneratedAssetHandler;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\SSViewer;

/**
 * Exports an anonymous, standalone document without recursively dispatching HTTP requests.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class OfflinePageExporter
{
    use Injectable;

    /** Uses one version-independent static document per page. */
    public function filename(OfflinePage $page): string
    {
        return 'developertools-offline/page-' . (int) $page->ID . '.html';
    }

    /** Reads only the published page, never the active CMS draft. */
    public function publishedPage(): ?OfflinePage
    {
        return Versioned::get_by_stage(OfflinePage::class, Versioned::LIVE)->first();
    }

    /** Publishes static output only after rendering and verifying a complete HTML document. */
    public function export(OfflinePage $page): void
    {
        $published = Versioned::get_by_stage(OfflinePage::class, Versioned::LIVE)->byID($page->ID);
        if (!$published || $published->CanViewType !== 'Anyone') {
            throw new RuntimeException('An unrestricted published offline page is required for export.');
        }
        $html = $this->render($published);
        if (!preg_match('/<html\b.*<\/html\s*>/is', $html)) {
            throw new RuntimeException('The offline page template did not render a complete HTML document.');
        }
        $store = Injector::inst()->get(GeneratedAssetHandler::class);
        $store->setContent($this->filename($published), $html);
        if ($store->getContent($this->filename($published)) !== $html) {
            throw new RuntimeException('The static offline page could not be stored. Check asset storage permissions.');
        }
    }

    /** Restores the frontend theme and prevents CMS identity or navigation being exported. */
    private function render(OfflinePage $page): string
    {
        $themes = SSViewer::get_themes();
        try {
            SSViewer::set_themes(SSViewer::config()->get('themes') ?: $themes);
            return Versioned::withVersionedMode(function () use ($page): string {
                Versioned::set_stage(Versioned::LIVE);
                return Member::actAs(null, fn() => (string) $page->renderWith([OfflinePage::class]));
            });
        } finally {
            SSViewer::set_themes($themes);
        }
    }

    /** Returns the existing export without dynamically rendering any frontend content. */
    public function content(OfflinePage $page): ?string
    {
        $content = Injector::inst()->get(GeneratedAssetHandler::class)->getContent($this->filename($page));
        return $content !== null && $content !== '' ? $content : null;
    }

    /** Removes only this page's generated document, never a shared directory. */
    public function remove(OfflinePage $page): void
    {
        Injector::inst()->get(GeneratedAssetHandler::class)->removeContent($this->filename($page));
    }
}

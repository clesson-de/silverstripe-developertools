<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Helpers;

use DOMElement;
use SilverStripe\Core\XssSanitiser;
use SilverStripe\View\Parsers\HTMLValue;

/**
 * Limits rendered Markdown to passive content that cannot escape the toast layout.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Helpers
 */
final class ToastHTML
{
    /** Passive formatting supported inside a notice. */
    private const ELEMENTS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'del', 's', 'a', 'img',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'hr',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    ];

    /** Sanitizes generated HTML, then removes non-formatting elements and attributes. */
    public static function sanitise(string $html): string
    {
        $value = HTMLValue::create(XssSanitiser::create()->sanitiseString($html));
        foreach (iterator_to_array($value->query('//body//*')) as $element) {
            if (!in_array($element->tagName, self::ELEMENTS, true)) {
                self::unwrap($element);
                continue;
            }
            self::cleanAttributes($element);
        }
        return $value->getContent();
    }

    /** Retains readable text without keeping forms, frames or layout containers. */
    private static function unwrap(DOMElement $element): void
    {
        while ($element->firstChild) {
            $element->parentNode->insertBefore($element->firstChild, $element);
        }
        $element->parentNode->removeChild($element);
    }

    /** Only links and images need attributes; no styles, classes, IDs or event handlers survive. */
    private static function cleanAttributes(DOMElement $element): void
    {
        $allowed = match ($element->tagName) {
            'a' => ['href', 'title'],
            'img' => ['src', 'alt', 'title'],
            default => [],
        };
        foreach (iterator_to_array($element->attributes) as $attribute) {
            if (!in_array($attribute->name, $allowed, true)
                || (in_array($attribute->name, ['href', 'src'], true) && !self::safeURL($attribute->value))) {
                $element->removeAttribute($attribute->name);
            }
        }
    }

    /** Allows ordinary web links and relative paths, but never executable/data schemes. */
    private static function safeURL(string $url): bool
    {
        $url = preg_replace('/[\x00-\x20\x7f]+/', '', $url);
        return !preg_match('/^([^\/?#]*):/', $url, $matches)
            || in_array(strtolower($matches[1]), ['https', 'http', 'mailto'], true);
    }
}

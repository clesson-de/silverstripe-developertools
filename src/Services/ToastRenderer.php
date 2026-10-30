<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Constants\ToastField as F;
use Clesson\Silverstripe\DeveloperTools\Constants\ToastType;
use Clesson\Silverstripe\DeveloperTools\Helpers\ToastHTML;
use Clesson\Silverstripe\DeveloperTools\Models\ToastMessage;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Model\ArrayData;
use SilverStripe\ORM\FieldType\DBField;

/**
 * Renders selected notices without exposing their IP restrictions or CMS records.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Services
 */
class ToastRenderer
{
    use Injectable;

    /** Stable marker also prevents injecting the same overlay twice. */
    public const OVERLAY_ID = 'developer-tools-toasts';
    /** Namespaced frontend distribution path. */
    private const ASSETS = 'clesson-de/silverstripe-developertools:client/frontend/dist/';

    /** Groups selected messages into bounded positional stacks. */
    public function render(array $messages): string
    {
        $groups = [];
        foreach ($messages as $message) {
            $key = $message->VerticalPosition . '-' . $message->HorizontalPosition;
            $groups[$key] ??= [
                'Horizontal' => $message->HorizontalPosition, 'Vertical' => $message->VerticalPosition, 'Messages' => [],
            ];
            $groups[$key]['Messages'][] = $this->messageData($message);
        }
        return (string) ArrayData::create($this->templateData(array_values($groups)))
            ->renderWith('Clesson/Silverstripe/DeveloperTools/Toasts');
    }

    /** Exposes only presentation attributes and safe message HTML. */
    private function messageData(ToastMessage $message): array
    {
        return [
            'Type' => $message->Type,
            'Title' => $message->dbObject(F::TITLE),
            'Role' => $message->Type === ToastType::ALERT ? 'alert' : 'status',
            'AutoHideSeconds' => $message->AutoHideSeconds,
            'Removable' => $message->Removable,
            'Content' => DBField::create_field('HTMLText', ToastHTML::sanitise(
                (string) $message->dbObject(F::MESSAGE)->getHTML()
            )),
        ];
    }

    /** Resolves exposed assets explicitly because page Requirements have already been rendered. */
    private function templateData(array $groups): array
    {
        return [
            'OverlayID' => self::OVERLAY_ID,
            'Groups' => $groups,
            'CloseLabel' => _t(__CLASS__ . '.CLOSE', 'Close message'),
            'RegionLabel' => _t(__CLASS__ . '.REGION', 'Website notices'),
            'CSSURL' => ModuleResourceLoader::resourceURL(self::ASSETS . 'toasts.css'),
            'JSURL' => ModuleResourceLoader::resourceURL(self::ASSETS . 'toasts.js'),
        ];
    }
}

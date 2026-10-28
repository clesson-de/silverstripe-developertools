<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Admins;

use Clesson\Silverstripe\DeveloperTools\Constants\ProjectInformationField;
use Clesson\Silverstripe\DeveloperTools\Constants\MonitoringStatus;
use Clesson\Silverstripe\DeveloperTools\Models\DeveloperToolsConfig;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoredWebsite;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringCheckService;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringTableRenderer;
use Clesson\Silverstripe\DeveloperTools\Services\ProjectInformationService;
use SilverStripe\Admin\SingleRecordAdmin;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Model\ArrayData;
use SilverStripe\ORM\FieldType\DBField;

/**
 * Administrator-only singleton editor and dashboard content endpoint.
 *
 * @package Clesson\Silverstripe\DeveloperTools
 * @subpackage Admins
 */
class DeveloperToolsAdmin extends SingleRecordAdmin
{
    /** @config */
    private static string $url_segment = 'developer-tools';

    /** @config */
    private static string $menu_title = 'Developer Tools';

    /** @config */
    private static float $menu_priority = -200;

    /** @config */
    private static string $menu_icon_class = 'font-icon-list';

    /** @config */
    private static string $required_permission_codes = 'ADMIN';

    /** @config */
    private static string $model_class = DeveloperToolsConfig::class;

    /** @config */
    private static bool $restrict_to_single_record = true;

    /** @config */
    private static bool $allow_new_record = true;

    /** @config */
    private static array $allowed_actions = [
        'projectInformationPanel',
        'monitoringDetails',
        'monitoringCheck',
        'monitoringWebsitesPanel',
    ];

    /**
     * Only administrators may access the editor and its dashboard panel.
     */
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member ?? Security::getCurrentUser());
    }

    /**
     * Renders project versions for the dashboard without exposing an API token.
     */
    public function projectInformationPanel(): HTTPResponse
    {
        if (!$this->canView()) {
            return HTTPResponse::create('', 403);
        }
        $rows = [];
        foreach (ProjectInformationService::singleton()->getInformation() as $key => $value) {
            $rows[] = ['Label' => ProjectInformationField::label($key), 'Value' => $value];
        }
        $html = ArrayData::create(['Rows' => $rows])->renderWith(
            'Clesson/Silverstripe/DeveloperTools/ProjectInformationPanel'
        );
        return HTTPResponse::create((string) $html)
            ->addHeader('Content-Type', 'text/html; charset=utf-8')
            ->addHeader('Cache-Control', 'private, no-store');
    }

    /** Returns cached version details to administrators only. */
    public function monitoringDetails(HTTPRequest $request): HTTPResponse
    {
        if (!$this->canView()) {
            return HTTPResponse::create('', 403);
        }
        $website = $this->monitoringWebsite($request);
        if (!$website) {
            return HTTPResponse::create('', 404);
        }
        return HTTPResponse::create(MonitoringTableRenderer::create()->render($website))
            ->addHeader('Content-Type', 'text/html; charset=utf-8')
            ->addHeader('Cache-Control', 'private, no-store');
    }

    /** Lists every configured website without making customer or provider requests. */
    public function monitoringWebsitesPanel(): HTTPResponse
    {
        if (!$this->canView()) {
            return HTTPResponse::create('', 403);
        }
        $rows = [];
        foreach (DeveloperToolsConfig::currentRecord()->Websites() as $website) {
            $rows[] = [
                'Name' => $website->Name, 'BaseURL' => $website->BaseURL,
                'Indicator' => DBField::create_field('HTMLText', MonitoringTableRenderer::create()->renderActionRequired($website)),
            ];
        }
        return $this->monitoringPanelResponse($rows);
    }

    /** Returns escaped panel content with private no-store caching. */
    private function monitoringPanelResponse(array $rows): HTTPResponse
    {
        $html = ArrayData::create([
            'Rows' => $rows,
            'WebsiteTitle' => _t(__CLASS__ . '.WEBSITE', 'Website'),
            'ActionTitle' => _t(__CLASS__ . '.ACTION_REQUIRED', 'Action required'),
            'Empty' => _t(__CLASS__ . '.NO_WEBSITES', 'No websites have been configured.'),
        ])->renderWith('Clesson/Silverstripe/DeveloperTools/MonitoringWebsitesPanel');
        return HTTPResponse::create((string) $html)
            ->addHeader('Content-Type', 'text/html; charset=utf-8')
            ->addHeader('Cache-Control', 'private, no-store');
    }

    /** Starts a bounded site check using a CSRF-protected administrator POST. */
    public function monitoringCheck(HTTPRequest $request): HTTPResponse
    {
        if (!$this->canView()) {
            return HTTPResponse::create('', 403);
        }
        if ($request->httpMethod() !== 'POST') {
            return HTTPResponse::create('', 405)->addHeader('Allow', 'POST');
        }
        if (!SecurityToken::inst()->checkRequest($request)) {
            return HTTPResponse::create('', 403);
        }
        return $this->performMonitoringCheck($request);
    }

    /** Keeps network errors visible while preserving the last valid inventory. */
    private function performMonitoringCheck(HTTPRequest $request): HTTPResponse
    {
        $website = $this->monitoringWebsite($request);
        if (!$website) {
            return HTTPResponse::create('', 404);
        }
        $check = MonitoringCheckService::singleton()->check($website);
        $website = MonitoredWebsite::get()->byID($website->ID);
        return HTTPResponse::create(json_encode([
            'status' => $check->Status, 'html' => MonitoringTableRenderer::create()->render($website),
            'action_required_html' => MonitoringTableRenderer::create()->renderActionRequired($website),
            'status_label' => MonitoringStatus::label($website->LastStatus),
            'checked_at' => $website->LastCheckedAt, 'change_at' => $website->getLastChangeAt(),
        ], JSON_THROW_ON_ERROR))
            ->addHeader('Content-Type', 'application/json; charset=utf-8')
            ->addHeader('Cache-Control', 'private, no-store');
    }

    /** Restricts records to this singleton instead of accepting arbitrary site IDs. */
    private function monitoringWebsite(HTTPRequest $request): ?MonitoredWebsite
    {
        return DeveloperToolsConfig::currentRecord()->Websites()->byID((int) $request->param('ID'));
    }
}

<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Admins;

use Clesson\Silverstripe\DeveloperTools\Constants\ProjectInformationField;
use Clesson\Silverstripe\DeveloperTools\Constants\MonitoringStatus;
use Clesson\Silverstripe\DeveloperTools\Models\DeveloperToolsConfig;
use Clesson\Silverstripe\DeveloperTools\Models\MonitoredWebsite;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringCheckService;
use Clesson\Silverstripe\DeveloperTools\Services\HealthCheckService;
use Clesson\Silverstripe\DeveloperTools\Services\AnalysisPdfRenderer;
use Clesson\Silverstripe\DeveloperTools\Services\HealthReportRenderer;
use Clesson\Silverstripe\DeveloperTools\Services\MonitoringTableRenderer;
use Clesson\Silverstripe\DeveloperTools\Services\ProjectInformationService;
use Clesson\Silverstripe\DeveloperTools\Services\ProjectLogReader;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Admin\SingleRecordAdmin;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Director;
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
    private static string $menu_icon_class = 'font-icon-explore-addons';

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
        'analysisReport',
        'analysisStatus',
        'analysisExecute',
        'analysisPrint',
        'analysisPdf',
        'runAnalysis',
        'monitoringDetails',
        'monitoringCheck',
        'monitoringWebsitesPanel',
        'logContent',
        'resetLogContent',
    ];

    private const ANALYSIS_SESSION_KEY = 'developer-tools.analysis.latest';
    private const ANALYSIS_MAX_SNAPSHOTS = 10;
    private const ANALYSIS_RUNS_SESSION_KEY = 'developer-tools.analysis.runs';
    private const ANALYSIS_MAX_RUNS = 25;

    /**
     * Only administrators may access the editor and its dashboard panel.
     */
    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member ?? Security::getCurrentUser());
    }

    /** Serves log data only to CMS administrators, as inert plain text. */
    public function logContent(HTTPRequest $request): HTTPResponse
    {
        if (!$this->canView()) {
            return HTTPResponse::create('', 403);
        }
        $name = $request->getVar('file');
        if (!is_string($name)) {
            return HTTPResponse::create('', 404);
        }
        try {
            $file = ProjectLogReader::singleton()->read($name);
        } catch (RuntimeException $exception) {
            Injector::inst()->get(LoggerInterface::class)->error('Unable to read project log.', ['exception' => $exception]);
            return HTTPResponse::create(_t(__CLASS__ . '.LOG_ERROR', 'The log file could not be read.'), 500)
                ->addHeader('Cache-Control', 'private, no-store');
        }
        if ($file === null) {
            return HTTPResponse::create('', 404)->addHeader('Cache-Control', 'private, no-store');
        }
        $notice = $file['truncated']
            ? _t(__CLASS__ . '.LOG_TRUNCATED', '[Only the last 1 MiB of this file is shown.]') . "\n\n"
            : '';
        return HTTPResponse::create($notice . $file['content'])
            ->addHeader('Content-Type', 'text/plain; charset=utf-8')
            ->addHeader('X-Content-Type-Options', 'nosniff')
            ->addHeader('X-Frame-Options', 'SAMEORIGIN')
            ->addHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'self'")
            ->addHeader('Cache-Control', 'private, no-store');
    }

    /** Empties a selected project log for administrators using CSRF-protected POST. */
    public function resetLogContent(HTTPRequest $request): HTTPResponse
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
        $name = $request->postVar('file');
        if (!is_string($name)) {
            return HTTPResponse::create('', 404)->addHeader('Cache-Control', 'private, no-store');
        }
        try {
            $cleared = ProjectLogReader::singleton()->clear($name);
        } catch (RuntimeException $exception) {
            Injector::inst()->get(LoggerInterface::class)->error('Unable to reset project log.', ['exception' => $exception]);
            return HTTPResponse::create(_t(__CLASS__ . '.LOG_RESET_ERROR', 'The log file could not be reset.'), 500)
                ->addHeader('Cache-Control', 'private, no-store');
        }
        if (!$cleared) {
            return HTTPResponse::create('', 404)->addHeader('Cache-Control', 'private, no-store');
        }
        return HTTPResponse::create('', 204)->addHeader('Cache-Control', 'private, no-store');
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
            if ($key === ProjectInformationField::DATABASE_VERSION) {
                $value = $this->withEnvironmentHint($value);
            }
            $rows[] = ['Label' => ProjectInformationField::label($key), 'Value' => $value];
        }
        $rows[] = [
            'Label' => _t(__CLASS__ . '.ENVIRONMENT', 'Environment'),
            'Value' => Director::isLive() ? 'LIVE' : 'DEV',
        ];
        $html = ArrayData::create(['Rows' => $rows])->renderWith(
            'Clesson/Silverstripe/DeveloperTools/ProjectInformationPanel'
        );
        return HTTPResponse::create((string) $html)
            ->addHeader('Content-Type', 'text/html; charset=utf-8')
            ->addHeader('Cache-Control', 'private, no-store');
    }

    /** Returns the latest cached analysis report HTML for this CMS session. */
    public function analysisReport(HTTPRequest $request): HTTPResponse
    {
        if (!$this->canView()) {
            return HTTPResponse::create('', 403);
        }
        $cached = $this->analysisSnapshot($request, (string) $request->getVar('id'));
        $payload = [
            'result_id' => is_array($cached) && isset($cached['id']) ? (string) $cached['id'] : '',
            'html' => is_array($cached) && isset($cached['html']) ? (string) $cached['html'] : '',
            'generated_at' => is_array($cached) && isset($cached['generated_at']) ? (string) $cached['generated_at'] : '',
        ];
        return HTTPResponse::create(json_encode($payload, JSON_THROW_ON_ERROR))
            ->addHeader('Content-Type', 'application/json; charset=utf-8')
            ->addHeader('Cache-Control', 'private, no-store');
    }

    /** Returns a printable snapshot page for a previously generated analysis result. */
    public function analysisPrint(HTTPRequest $request): HTTPResponse
    {
        if (!$this->canView()) {
            return HTTPResponse::create('', 403);
        }
        $cached = $this->analysisSnapshot($request, (string) $request->getVar('id'));
        if (!is_array($cached) || !isset($cached['html'])) {
            return HTTPResponse::create('', 404)->addHeader('Cache-Control', 'private, no-store');
        }
        $title = _t(__CLASS__ . '.ANALYSIS_PRINT_TITLE', 'System health report');
        $html = sprintf(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>%s</title></head><body>%s</body></html>',
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            (string) $cached['html']
        );
        return HTTPResponse::create($html)
            ->addHeader('Content-Type', 'text/html; charset=utf-8')
            ->addHeader('Cache-Control', 'private, no-store');
    }

    /** Returns a PDF file for a previously generated analysis snapshot. */
    public function analysisPdf(HTTPRequest $request): HTTPResponse
    {
        if (!$this->canView()) {
            return HTTPResponse::create('', 403);
        }
        $cached = $this->analysisSnapshot($request, (string) $request->getVar('id'));
        if (!is_array($cached) || !isset($cached['html'])) {
            return HTTPResponse::create('', 404)->addHeader('Cache-Control', 'private, no-store');
        }
        try {
            $title = _t(__CLASS__ . '.ANALYSIS_PRINT_TITLE', 'System health report');
            $generatedAt = isset($cached['generated_at']) ? (string) $cached['generated_at'] : '';
            if (isset($cached['report']) && is_array($cached['report'])) {
                $pdf = AnalysisPdfRenderer::create()->renderReport($title, $cached['report'], $generatedAt);
            } else {
                $pdf = AnalysisPdfRenderer::create()->render($title, (string) $cached['html'], $generatedAt);
            }
            return HTTPResponse::create($pdf)
                ->addHeader('Content-Type', 'application/pdf')
                ->addHeader('Content-Disposition', 'inline; filename="system-health-report.pdf"')
                ->addHeader('Cache-Control', 'private, no-store');
        } catch (Throwable $exception) {
            Injector::inst()->get(LoggerInterface::class)->error('Unable to render analysis PDF.', ['exception' => $exception]);
            return HTTPResponse::create(_t(__CLASS__ . '.ANALYSIS_PDF_ERROR', 'The report PDF could not be generated.'), 500)
                ->addHeader('Cache-Control', 'private, no-store');
        }
    }

    /** Returns the status of a previously started analysis run. */
    public function analysisStatus(HTTPRequest $request): HTTPResponse
    {
        if (!$this->canView()) {
            return HTTPResponse::create('', 403);
        }
        $runId = (string) $request->getVar('run_id');
        if ($runId === '') {
            return HTTPResponse::create('', 404)->addHeader('Cache-Control', 'private, no-store');
        }
        $run = $this->analysisRun($request, $runId);
        if (!is_array($run)) {
            return HTTPResponse::create('', 404)->addHeader('Cache-Control', 'private, no-store');
        }
        $payload = [
            'run_id' => $runId,
            'status' => (string) ($run['status'] ?? 'failed'),
            'progress' => (int) ($run['progress'] ?? 0),
            'result_id' => (string) ($run['result_id'] ?? ''),
            'html' => (string) ($run['html'] ?? ''),
            'generated_at' => (string) ($run['generated_at'] ?? ''),
            'error' => (string) ($run['error'] ?? ''),
        ];
        return HTTPResponse::create(json_encode($payload, JSON_THROW_ON_ERROR))
            ->addHeader('Content-Type', 'application/json; charset=utf-8')
            ->addHeader('Cache-Control', 'private, no-store');
    }

    /** Runs analysis on demand and stores the report snapshot in this CMS session. */
    public function runAnalysis(HTTPRequest $request): HTTPResponse
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
        $runId = bin2hex(random_bytes(12));
        $this->storeAnalysisRun($request, [
            'id' => $runId,
            'status' => 'queued',
            'progress' => 0,
            'result_id' => '',
            'html' => '',
            'generated_at' => '',
            'error' => '',
            'started_at' => time(),
        ]);
        return HTTPResponse::create(json_encode([
            'run_id' => $runId,
            'status' => 'queued',
        ], JSON_THROW_ON_ERROR), 202)
            ->addHeader('Content-Type', 'application/json; charset=utf-8')
            ->addHeader('Cache-Control', 'private, no-store');
    }

    /** Executes a previously queued analysis run. */
    public function analysisExecute(HTTPRequest $request): HTTPResponse
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
        $runId = (string) $request->postVar('run_id');
        if ($runId === '') {
            return HTTPResponse::create('', 404)->addHeader('Cache-Control', 'private, no-store');
        }
        $run = $this->analysisRun($request, $runId);
        if (!is_array($run)) {
            return HTTPResponse::create('', 404)->addHeader('Cache-Control', 'private, no-store');
        }
        if (($run['status'] ?? '') === 'done' || ($run['status'] ?? '') === 'failed') {
            return HTTPResponse::create('', 204)->addHeader('Cache-Control', 'private, no-store');
        }
        $run['status'] = 'running';
        $run['progress'] = 25;
        $this->storeAnalysisRun($request, $run);
        $this->completeAnalysisRun($request, $run);
        return HTTPResponse::create('', 202)->addHeader('Cache-Control', 'private, no-store');
    }

    /** @return array{id: string, html: string, generated_at: string, report?: array<int, array<string, string>>}|null */
    private function analysisSnapshot(HTTPRequest $request, string $id = ''): ?array
    {
        $cache = $request->getSession()->get(self::ANALYSIS_SESSION_KEY);
        if (!is_array($cache) || !isset($cache['items']) || !is_array($cache['items'])) {
            return null;
        }
        if ($id !== '' && isset($cache['items'][$id]) && is_array($cache['items'][$id])) {
            return $cache['items'][$id];
        }
        $latest = $cache['latest'] ?? '';
        return is_string($latest) && isset($cache['items'][$latest]) && is_array($cache['items'][$latest])
            ? $cache['items'][$latest]
            : null;
    }

    /** @param array{id: string, html: string, generated_at: string, report?: array<int, array<string, string>>} $snapshot */
    private function storeAnalysisSnapshot(HTTPRequest $request, array $snapshot): void
    {
        $session = $request->getSession();
        $cache = $session->get(self::ANALYSIS_SESSION_KEY);
        if (!is_array($cache) || !isset($cache['items']) || !is_array($cache['items'])) {
            $cache = ['latest' => '', 'items' => [], 'order' => []];
        }
        $cache['items'][$snapshot['id']] = $snapshot;
        $order = array_values(array_filter((array) ($cache['order'] ?? []), static fn($value): bool => is_string($value)));
        $order = array_values(array_filter($order, static fn(string $value): bool => $value !== $snapshot['id']));
        $order[] = $snapshot['id'];
        while (count($order) > self::ANALYSIS_MAX_SNAPSHOTS) {
            $remove = array_shift($order);
            if ($remove !== null) {
                unset($cache['items'][$remove]);
            }
        }
        $cache['latest'] = $snapshot['id'];
        $cache['order'] = $order;
        $session->set(self::ANALYSIS_SESSION_KEY, $cache);
    }

    /** @return array<string, mixed>|null */
    private function analysisRun(HTTPRequest $request, string $runId): ?array
    {
        $cache = $request->getSession()->get(self::ANALYSIS_RUNS_SESSION_KEY);
        if (!is_array($cache) || !isset($cache['items']) || !is_array($cache['items'])) {
            return null;
        }
        return isset($cache['items'][$runId]) && is_array($cache['items'][$runId])
            ? $cache['items'][$runId]
            : null;
    }

    /** @param array<string, mixed> $run @return array<string, mixed> */
    private function completeAnalysisRun(HTTPRequest $request, array $run): array
    {
        try {
            $id = bin2hex(random_bytes(12));
            $report = HealthCheckService::singleton()->collect();
            $html = HealthReportRenderer::create()->render($report);
            $snapshot = ['id' => $id, 'html' => $html, 'generated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'report' => $report];
            $this->storeAnalysisSnapshot($request, $snapshot);
            $run['status'] = 'done';
            $run['progress'] = 100;
            $run['result_id'] = $snapshot['id'];
            $run['html'] = $snapshot['html'];
            $run['generated_at'] = $snapshot['generated_at'];
            $run['error'] = '';
        } catch (Throwable $exception) {
            Injector::inst()->get(LoggerInterface::class)->error('Unable to run analysis.', ['exception' => $exception]);
            $run['status'] = 'failed';
            $run['progress'] = 100;
            $run['result_id'] = '';
            $run['html'] = '';
            $run['generated_at'] = '';
            $run['error'] = _t(__CLASS__ . '.ANALYSIS_RUN_ERROR', 'The analysis could not be completed.');
        }
        $this->storeAnalysisRun($request, $run);
        return $run;
    }

    /** @param array{id: string, status: string, progress: int, result_id: string, html: string, generated_at: string, error: string, started_at?: int} $run */
    private function storeAnalysisRun(HTTPRequest $request, array $run): void
    {
        $session = $request->getSession();
        $cache = $session->get(self::ANALYSIS_RUNS_SESSION_KEY);
        if (!is_array($cache) || !isset($cache['items']) || !is_array($cache['items'])) {
            $cache = ['items' => [], 'order' => []];
        }
        $cache['items'][$run['id']] = $run;
        $order = array_values(array_filter((array) ($cache['order'] ?? []), static fn($value): bool => is_string($value)));
        $order = array_values(array_filter($order, static fn(string $value): bool => $value !== $run['id']));
        $order[] = $run['id'];
        while (count($order) > self::ANALYSIS_MAX_RUNS) {
            $remove = array_shift($order);
            if ($remove !== null) {
                unset($cache['items'][$remove]);
            }
        }
        $cache['order'] = $order;
        $session->set(self::ANALYSIS_RUNS_SESSION_KEY, $cache);
    }

    private function withEnvironmentHint(string $databaseVersion): string
    {
        return sprintf('%s (%s)', $databaseVersion, Director::isLive() ? 'LIVE' : 'DEV');
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

<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Services;

use Clesson\Silverstripe\DeveloperTools\Constants\HealthStatus;

/**
 * Renders immutable analysis snapshots into a simple, dependency-free PDF.
 */
class AnalysisPdfRenderer
{
    /** @var array<string, int> */
    private const STATUS_ORDER = [
        HealthStatus::ERROR => 0,
        HealthStatus::WARNING => 1,
        HealthStatus::UNKNOWN => 2,
        HealthStatus::OK => 3,
    ];

    /** @var array<string, int> */
    private const STATUS_RISK_POINTS = [
        HealthStatus::ERROR => 5,
        HealthStatus::WARNING => 2,
        HealthStatus::UNKNOWN => 1,
        HealthStatus::OK => 0,
    ];

    public static function create(): self
    {
        return new self();
    }

    public function render(string $title, string $html, string $generatedAt = ''): string
    {
        $header = $generatedAt !== ''
            ? sprintf("%s\nGenerated at: %s", $title, $generatedAt)
            : $title;
        $text = $this->normalizeText($header . "\n\n" . $this->htmlToText($html));
        $lines = explode("\n", $text);
        $lines = $this->wrapLines($lines, 88);
        $pages = [];
        $chunkSize = 50;
        for ($index = 0, $count = count($lines); $index < $count; $index += $chunkSize) {
            $pages[] = array_slice($lines, $index, $chunkSize);
        }
        if ($pages === []) {
            $pages[] = [''];
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';

        $fontObjectId = 3;
        $objects[$fontObjectId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $pageObjectIds = [];
        $objectId = 4;
        $totalPages = count($pages);
        foreach ($pages as $pageNumber => $pageLines) {
            $pageId = $objectId++;
            $contentId = $objectId++;
            $pageObjectIds[] = $pageId;
            $objects[$contentId] = $this->contentStreamObject($this->pageContent($pageLines, $pageNumber + 1, $totalPages));
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >>',
                $fontObjectId,
                $contentId
            );
        }

        $kids = implode(' ', array_map(static fn(int $id): string => sprintf('%d 0 R', $id), $pageObjectIds));
        $objects[2] = sprintf('<< /Type /Pages /Count %d /Kids [%s] >>', count($pageObjectIds), $kids);

        ksort($objects);
        return $this->pdfFromObjects($objects);
    }

    /**
     * @param array<int, array{id: string, title: string, status: string, message: string, checked_at: string}> $report
     */
    public function renderReport(string $title, array $report, string $generatedAt = ''): string
    {
        $normalized = (new HealthReport())->normalize($report, true);
        usort($normalized, function (array $left, array $right): int {
            $leftRank = self::STATUS_ORDER[$left['status']] ?? 99;
            $rightRank = self::STATUS_ORDER[$right['status']] ?? 99;
            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }
            return strcmp((string) $left['title'], (string) $right['title']);
        });
        $counts = [
            HealthStatus::ERROR => 0,
            HealthStatus::WARNING => 0,
            HealthStatus::UNKNOWN => 0,
            HealthStatus::OK => 0,
        ];
        foreach ($normalized as $row) {
            $status = (string) $row['status'];
            if (isset($counts[$status])) {
                $counts[$status] += 1;
            }
        }
        $maxPointsPerCheck = self::STATUS_RISK_POINTS[HealthStatus::ERROR];
        $riskPoints = 0;
        foreach ($counts as $status => $count) {
            $riskPoints += (self::STATUS_RISK_POINTS[$status] ?? 0) * $count;
        }
        $maxRiskPoints = count($normalized) * $maxPointsPerCheck;
        $healthPercent = $maxRiskPoints > 0
            ? (int) round((1 - ($riskPoints / $maxRiskPoints)) * 100)
            : 100;

        $lines = [];
        $lines[] = $title;
        if ($generatedAt !== '') {
            $lines[] = sprintf('Generated at: %s', $generatedAt);
        }
        $lines[] = sprintf('Risk points: %d/%d (health %d%%)', $riskPoints, $maxRiskPoints, $healthPercent);
        $legendTemplate = _t(
            __CLASS__ . '.LEGEND',
            'Legend: ERROR={error}, WARNING={warning}, UNKNOWN={unknown}, OK={ok}'
        );
        $lines[] = strtr($legendTemplate, [
            '{error}' => (string) self::STATUS_RISK_POINTS[HealthStatus::ERROR],
            '{warning}' => (string) self::STATUS_RISK_POINTS[HealthStatus::WARNING],
            '{unknown}' => (string) self::STATUS_RISK_POINTS[HealthStatus::UNKNOWN],
            '{ok}' => (string) self::STATUS_RISK_POINTS[HealthStatus::OK],
        ]);
        $lines[] = sprintf(
            'Summary: ERROR %d | WARNING %d | UNKNOWN %d | OK %d',
            $counts[HealthStatus::ERROR],
            $counts[HealthStatus::WARNING],
            $counts[HealthStatus::UNKNOWN],
            $counts[HealthStatus::OK]
        );
        $lines[] = '';
        $lines[] = 'Top risks (ERROR/WARNING):';
        $risks = array_values(array_filter($normalized, static function (array $row): bool {
            return $row['status'] === HealthStatus::ERROR || $row['status'] === HealthStatus::WARNING;
        }));
        if ($risks === []) {
            $lines[] = '- None';
        } else {
            foreach (array_slice($risks, 0, 10) as $risk) {
                $label = strtoupper((string) HealthStatus::label((string) $risk['status']));
                $lines[] = sprintf('- [%s] %s', $label, (string) $risk['title']);
            }
        }
        $lines[] = '';
        $lines[] = 'Status     | Check';
        $lines[] = '-----------+--------------------------------------------------------------';
        foreach ($normalized as $row) {
            $label = strtoupper((string) HealthStatus::label((string) $row['status']));
            $lines[] = sprintf('%-10s | %s', $label, (string) $row['title']);
            $lines[] = sprintf('           | %s', (string) $row['message']);
            $lines[] = sprintf('           | %s', (string) $row['checked_at']);
            $lines[] = '';
        }
        return $this->render($title, nl2br(implode("\n", $lines)), $generatedAt);
    }

    private function htmlToText(string $html): string
    {
        $withBreaks = preg_replace('/<(br|\\/p|\\/li|\\/h[1-6]|\\/tr)>/i', "\n", $html) ?? $html;
        $stripped = strip_tags($withBreaks);
        $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $decoded;
    }

    private function normalizeText(string $text): string
    {
        $normalized = preg_replace('/\R/u', "\n", $text) ?? $text;
        $normalized = preg_replace('/[\t ]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\n{3,}/u', "\n\n", $normalized) ?? $normalized;
        return trim($normalized);
    }

    private function pageContent(array $lines, int $pageNumber, int $totalPages): string
    {
        $content = [
            'BT',
            '/F1 10 Tf',
            '50 800 Td',
            '12 TL',
        ];
        foreach ($lines as $index => $line) {
            $escaped = $this->escapePdfText($line);
            if ($index > 0) {
                $content[] = 'T*';
            }
            $content[] = sprintf('(%s) Tj', $escaped);
        }
        $content[] = 'ET';
        $content[] = 'BT';
        $content[] = '/F1 9 Tf';
        $content[] = '50 24 Td';
        $content[] = sprintf('(%s) Tj', $this->escapePdfText(sprintf('Page %d of %d', $pageNumber, $totalPages)));
        $content[] = 'ET';
        return implode("\n", $content) . "\n";
    }

    /** @param array<int, string> $lines @return array<int, string> */
    private function wrapLines(array $lines, int $maxLength): array
    {
        $wrapped = [];
        foreach ($lines as $line) {
            $clean = trim($line);
            if ($clean === '') {
                $wrapped[] = '';
                continue;
            }
            while (strlen($clean) > $maxLength) {
                $breakAt = strrpos(substr($clean, 0, $maxLength + 1), ' ');
                if ($breakAt === false || $breakAt < (int) ($maxLength * 0.6)) {
                    $breakAt = $maxLength;
                }
                $wrapped[] = rtrim(substr($clean, 0, $breakAt));
                $clean = ltrim(substr($clean, $breakAt));
            }
            $wrapped[] = $clean;
        }
        return $wrapped;
    }

    private function contentStreamObject(string $stream): string
    {
        return sprintf("<< /Length %d >>\nstream\n%sendstream", strlen($stream), $stream);
    }

    /** @param array<int, string> $objects */
    private function pdfFromObjects(array $objects): string
    {
        $pdf = "%PDF-1.4\n%Generated by silverstripe-developertools\n";
        $offsets = [0 => 0];
        $maxObject = 0;
        foreach ($objects as $id => $body) {
            $maxObject = max($maxObject, (int) $id);
            $offsets[$id] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $body);
        }

        $xrefStart = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n", $maxObject + 1);
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxObject; $id++) {
            $offset = $offsets[$id] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF",
            $maxObject + 1,
            $xrefStart
        );
        return $pdf;
    }

    private function escapePdfText(string $value): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
        $ascii = str_replace(["\\r", "\\n"], [' ', ' '], $ascii);
        $ascii = str_replace('\\', '\\\\', $ascii);
        $ascii = str_replace('(', '\\(', $ascii);
        $ascii = str_replace(')', '\\)', $ascii);
        return $ascii;
    }
}










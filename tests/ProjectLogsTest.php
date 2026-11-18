<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\DeveloperTools\Tests;

use Clesson\Silverstripe\DeveloperTools\Admins\DeveloperToolsAdmin;
use Clesson\Silverstripe\DeveloperTools\Forms\ProjectLogsTab;
use Clesson\Silverstripe\DeveloperTools\Services\ProjectLogReader;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;

class ProjectLogsTest extends FunctionalTest
{
    protected $usesDatabase = true;
    protected $autoFollowRedirection = false;
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/project-logs-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        Config::modify()->set(ProjectLogReader::class, 'directory', $this->directory);
        file_put_contents($this->directory . '/application.log', "hello <script>alert(1)</script>\n");
        file_put_contents($this->directory . '/ignored.txt', 'private');
    }

    protected function tearDown(): void
    {
        foreach (new \DirectoryIterator($this->directory) as $entry) {
            if (!$entry->isDot()) {
                if ($entry->isDir() && !$entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testListsOnlyRegularLogFilesAndEscapesNames(): void
    {
        file_put_contents($this->directory . '/<danger>.log', 'content');
        mkdir($this->directory . '/nested.log');
        symlink($this->directory . '/application.log', $this->directory . '/link.log');
        $files = ProjectLogReader::create()->files();
        self::assertSame(['<danger>.log', 'application.log'], $files);
        $html = (string) (new ProjectLogsTab())->build()->getChildren()->first()->getContent();
        self::assertStringContainsString('&lt;danger&gt;.log', $html);
        self::assertStringNotContainsString('ignored.txt', $html);
        self::assertStringContainsString('sandbox', $html);
        self::assertStringContainsString('min="5"', $html);
        self::assertStringContainsString('max="360"', $html);
    }

    public function testAccessAndPlainTextSecurity(): void
    {
        $url = DeveloperToolsAdmin::singleton()->Link('logContent') . '?file=application.log';
        self::assertNotSame(200, $this->get($url)->getStatusCode());
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        self::assertNotSame(200, $this->get($url)->getStatusCode());
        $this->logInWithPermission('ADMIN');
        $response = $this->get($url);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame("hello <script>alert(1)</script>\n", $response->getBody());
        self::assertStringContainsString('text/plain', $response->getHeader('Content-Type'));
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        self::assertStringContainsString('sandbox', $response->getHeader('Content-Security-Policy'));
        self::assertStringContainsString('no-store', $response->getHeader('Cache-Control'));
        symlink($this->directory . '/application.log', $this->directory . '/link.log');
        foreach (['../application.log', 'ignored.txt', 'missing.log', 'nested/application.log', 'link.log'] as $name) {
            $response = $this->get(DeveloperToolsAdmin::singleton()->Link('logContent') . '?file=' . rawurlencode($name));
            self::assertSame(404, $response->getStatusCode(), $name);
        }
    }

    public function testLargeFileIsLimitedToLastMebibyte(): void
    {
        file_put_contents($this->directory . '/large.log', 'start' . str_repeat('x', 1048576));
        $this->logInWithPermission('ADMIN');
        $response = $this->get(DeveloperToolsAdmin::singleton()->Link('logContent') . '?file=large.log');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(_t(DeveloperToolsAdmin::class . '.LOG_TRUNCATED',
            '[Only the last 1 MiB of this file is shown.]'), $response->getBody());
        self::assertStringNotContainsString('start', $response->getBody());
        self::assertStringEndsWith(str_repeat('x', 1048576), $response->getBody());
    }

    public function testMissingDirectoryIsVisible(): void
    {
        Config::modify()->set(ProjectLogReader::class, 'directory', $this->directory . '/missing');
        $html = (string) (new ProjectLogsTab())->build()->getChildren()->first()->getContent();
        self::assertStringContainsString(_t(ProjectLogsTab::class . '.DIRECTORY_ERROR',
            'The project logs directory is missing or unreadable.'), $html);
        $this->logInWithPermission('ADMIN');
        $response = $this->get(DeveloperToolsAdmin::singleton()->Link('logContent') . '?file=application.log');
        self::assertSame(500, $response->getStatusCode());
    }
}

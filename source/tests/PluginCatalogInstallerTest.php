<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Core\Plugins\PluginAuditCache;
use Latch\Core\Plugins\PluginAuditService;
use Latch\Core\Plugins\PluginAuditor;
use Latch\Core\Plugins\PluginCatalogEntry;
use Latch\Core\Plugins\PluginCatalogInstaller;
use Latch\Core\Plugins\PluginInstaller;
use Latch\Core\Plugins\PluginRegistry;
use Latch\Core\Plugins\PluginReleaseDownloader;
use Latch\Models\SettingRepository;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class PluginCatalogInstallerTest extends TestCase
{
    private string $root;
    private string $pluginsPath;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/latch-catalog-install-' . bin2hex(random_bytes(4));
        $this->pluginsPath = $this->root . '/plugins';
        $this->storagePath = $this->root . '/storage';
        mkdir($this->pluginsPath, 0775, true);
        mkdir($this->storagePath . '/cache/plugin-audits', 0775, true);

        $db = new Database($this->storagePath . '/latch.sqlite');
        $db->pdo()->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->settings = new SettingRepository($db);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
    }

    private SettingRepository $settings;

    public function testInstallsCatalogPluginAndLeavesDisabled(): void
    {
        $source = dirname(__DIR__) . '/docs/plugins/example';
        $zipBytes = $this->zipDirectory($source);
        $entry = PluginCatalogEntry::fromArray([
            'slug' => 'example',
            'name' => 'Example plugin',
            'version' => '1.0.0',
            'min_latch_version' => '0.3.0',
            'summary' => 'Reference plugin',
            'hooks' => ['bootstrap', 'route.register'],
        ]);
        $url = $entry->releaseZipUrl('YeOK/Latch-plugins', 'v1.0.0');
        $http = new StubPluginHttpClient([
            $url => [
                'status' => 200,
                'body' => $zipBytes,
            ],
        ]);

        $installer = $this->catalogInstaller($http);
        $manifest = $installer->install($entry, 'v1.0.0');

        $this->assertSame('example', $manifest->slug);
        $this->assertDirectoryExists($this->pluginsPath . '/example');
        $registry = new PluginRegistry($this->pluginsPath, $this->settings);
        $this->assertFalse($registry->isEnabled('example'));
    }

    public function testRollsBackWhenAuditFails(): void
    {
        $source = dirname(__DIR__) . '/docs/plugins/badexample';
        $zipBytes = $this->zipDirectory($source);
        $entry = PluginCatalogEntry::fromArray([
            'slug' => 'badexample',
            'name' => 'Bad example',
            'version' => '1.0.0',
            'min_latch_version' => '0.3.0',
            'summary' => 'Audit failure fixture',
            'hooks' => ['bootstrap'],
        ]);
        $url = $entry->releaseZipUrl('YeOK/Latch-plugins', 'v1.0.0');
        $http = new StubPluginHttpClient([
            $url => [
                'status' => 200,
                'body' => $zipBytes,
            ],
        ]);

        $installer = $this->catalogInstaller($http);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Install rolled back');
        $installer->install($entry, 'v1.0.0');
    }

    private function catalogInstaller(StubPluginHttpClient $http): PluginCatalogInstaller
    {
        $auditor = new PluginAuditor(
            dirname(__DIR__),
            $this->pluginsPath,
            $this->storagePath,
        );

        return new PluginCatalogInstaller(
            new PluginInstaller($this->pluginsPath, $this->storagePath),
            new PluginAuditService($auditor, new PluginAuditCache($this->storagePath . '/cache/plugin-audits')),
            new PluginRegistry($this->pluginsPath, $this->settings),
            new PluginReleaseDownloader('YeOK/Latch-plugins', $http),
            $this->storagePath,
        );
    }

    private function zipDirectory(string $sourceDir): string
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('php-zip extension not available');
        }

        $zipPath = $this->root . '/fixture.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = ltrim(str_replace($sourceDir, '', $file->getPathname()), '/\\');
            $zip->addFile($file->getPathname(), basename($sourceDir) . '/' . $relative);
        }

        $zip->close();

        $bytes = file_get_contents($zipPath);
        $this->assertIsString($bytes);

        return $bytes;
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
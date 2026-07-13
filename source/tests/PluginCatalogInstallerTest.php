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

    public function testUpdatesInstalledPluginAndPreservesEnabledState(): void
    {
        $source = dirname(__DIR__) . '/docs/plugins/example';
        $zipBytesV1 = $this->zipDirectory($source, '1.0.0');
        $zipBytesV2 = $this->zipDirectory($source, '1.0.1');
        $entryV1 = PluginCatalogEntry::fromArray([
            'slug' => 'example',
            'name' => 'Example plugin',
            'version' => '1.0.0',
            'min_latch_version' => '0.3.0',
            'summary' => 'Reference plugin',
            'hooks' => ['bootstrap', 'route.register'],
        ]);
        $entryV2 = PluginCatalogEntry::fromArray([
            'slug' => 'example',
            'name' => 'Example plugin',
            'version' => '1.0.1',
            'min_latch_version' => '0.3.0',
            'summary' => 'Reference plugin',
            'hooks' => ['bootstrap', 'route.register'],
        ]);

        $http = new StubPluginHttpClient([
            $entryV1->releaseZipUrl('YeOK/Latch-plugins', 'v1.0.0') => [
                'status' => 200,
                'body' => $zipBytesV1,
            ],
            $entryV2->releaseZipUrl('YeOK/Latch-plugins', 'v1.0.1') => [
                'status' => 200,
                'body' => $zipBytesV2,
            ],
        ]);

        $installer = $this->catalogInstaller($http);
        $installer->install($entryV1, 'v1.0.0');

        $registry = new PluginRegistry($this->pluginsPath, $this->settings);
        $registry->setEnabledSlugs(['example']);
        $this->assertTrue($registry->isEnabled('example'));

        $result = $installer->update($entryV2, 'v1.0.1');

        $this->assertSame('1.0.1', $result['manifest']->version);
        $this->assertSame('1.0.0', $result['previous_version']);
        $this->assertTrue($result['was_enabled']);
        $this->assertTrue($registry->isEnabled('example'));
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

    private function zipDirectory(string $sourceDir, string $version = '1.0.0'): string
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('php-zip extension not available');
        }

        $stage = $this->root . '/stage-' . bin2hex(random_bytes(4));
        mkdir($stage, 0775, true);
        $pluginStage = $stage . '/' . basename($sourceDir);
        $this->copyTree($sourceDir, $pluginStage);
        $manifestPath = $pluginStage . '/plugin.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['version'] = $version;
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        $zipPath = $this->root . '/fixture-' . $version . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pluginStage, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = ltrim(str_replace($pluginStage, '', $file->getPathname()), '/\\');
            $zip->addFile($file->getPathname(), basename($pluginStage) . '/' . $relative);
        }

        $zip->close();
        $this->deleteTree($stage);

        $bytes = file_get_contents($zipPath);
        $this->assertIsString($bytes);

        return $bytes;
    }

    private function copyTree(string $source, string $destination): void
    {
        if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new \RuntimeException('Could not create directory: ' . $destination);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $relative = ltrim(str_replace($source, '', $item->getPathname()), '/\\');
            $target = $destination . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new \RuntimeException('Could not create directory: ' . $target);
                }
                continue;
            }

            copy($item->getPathname(), $target);
        }
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
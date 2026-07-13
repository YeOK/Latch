<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginInstaller;
use Latch\Core\Plugins\PluginManifest;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class PluginInstallerTest extends TestCase
{
    private string $root;
    private string $pluginsPath;
    private string $storagePath;
    private PluginInstaller $installer;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/latch-plugin-installer-' . bin2hex(random_bytes(4));
        $this->pluginsPath = $this->root . '/plugins';
        $this->storagePath = $this->root . '/storage';
        mkdir($this->pluginsPath, 0775, true);
        mkdir($this->storagePath, 0775, true);
        $this->installer = new PluginInstaller($this->pluginsPath, $this->storagePath);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
    }

    public function testInstallFromDirectory(): void
    {
        $source = $this->makeSourcePlugin('copy-me');

        $manifest = $this->installer->installFromSource($source);

        $this->assertSame('copy-me', $manifest->slug);
        $this->assertFileExists($this->pluginsPath . '/copy-me/plugin.json');
        $this->assertFileExists($this->pluginsPath . '/copy-me/src/Plugin.php');
        $this->assertStringContainsString('CopyMe', file_get_contents($this->pluginsPath . '/copy-me/src/Plugin.php'));
    }

    public function testInstallFromZipWithSingleTopLevelDirectory(): void
    {
        $source = $this->makeSourcePlugin('zip-plugin');
        $zipPath = $this->root . '/zip-plugin.zip';
        $this->createZipFromDirectory($source, $zipPath);

        $manifest = $this->installer->installFromSource($zipPath);

        $this->assertSame('zip-plugin', $manifest->slug);
        $this->assertDirectoryExists($this->pluginsPath . '/zip-plugin');
    }

    public function testInstallRejectsExistingSlug(): void
    {
        $source = $this->makeSourcePlugin('dupe');
        $this->installer->installFromSource($source);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already installed');
        $this->installer->installFromSource($source);
    }

    public function testInstallRejectsRemoteUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote URLs are not supported');
        $this->installer->installFromSource('https://example.com/plugin.zip');
    }

    public function testRemoveDeletesPluginDirectory(): void
    {
        $source = $this->makeSourcePlugin('gone');
        $this->installer->installFromSource($source);
        mkdir($this->storagePath . '/plugins/gone', 0775, true);
        file_put_contents($this->storagePath . '/plugins/gone/state.txt', 'keep-me');

        $this->installer->removeInstalled('gone');

        $this->assertDirectoryDoesNotExist($this->pluginsPath . '/gone');
        $this->assertDirectoryExists($this->storagePath . '/plugins/gone');
    }

    public function testRemoveCanPurgeStorage(): void
    {
        $source = $this->makeSourcePlugin('purge-me');
        $this->installer->installFromSource($source);
        mkdir($this->storagePath . '/plugins/purge-me', 0775, true);
        file_put_contents($this->storagePath . '/plugins/purge-me/state.txt', 'delete-me');

        $this->installer->removeInstalled('purge-me', true);

        $this->assertDirectoryDoesNotExist($this->pluginsPath . '/purge-me');
        $this->assertDirectoryDoesNotExist($this->storagePath . '/plugins/purge-me');
    }

    public function testRemoveUnknownSlugFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->installer->removeInstalled('missing');
    }

    public function testUpgradeReplacesCodeAndPreservesStorage(): void
    {
        $source = $this->makeSourcePlugin('upgrade-me', '1.0.0', 'VersionOne');
        $this->installer->installFromSource($source);
        mkdir($this->storagePath . '/plugins/upgrade-me', 0775, true);
        file_put_contents($this->storagePath . '/plugins/upgrade-me/settings.json', '{"enabled":true}');

        $updatedSource = $this->makeSourcePlugin('upgrade-me', '1.0.1', 'VersionTwo');
        $upgrade = $this->installer->upgradeFromSource($updatedSource, 'upgrade-me');
        $upgrade->commit();

        $this->assertSame('1.0.1', $upgrade->manifest->version);
        $this->assertStringContainsString(
            'VersionTwo',
            (string) file_get_contents($this->pluginsPath . '/upgrade-me/src/Plugin.php'),
        );
        $this->assertSame(
            '{"enabled":true}',
            (string) file_get_contents($this->storagePath . '/plugins/upgrade-me/settings.json'),
        );
    }

    public function testUpgradeRollsBackOnAuditFailurePath(): void
    {
        $source = $this->makeSourcePlugin('rollback-me', '1.0.0', 'KeepMe');
        $this->installer->installFromSource($source);

        $updatedSource = $this->makeSourcePlugin('rollback-me', '1.0.1', 'Broken');
        $upgrade = $this->installer->upgradeFromSource($updatedSource, 'rollback-me');
        $upgrade->rollback();

        $manifest = PluginManifest::fromDirectory($this->pluginsPath . '/rollback-me');
        $this->assertSame('1.0.0', $manifest->version);
        $this->assertStringContainsString(
            'KeepMe',
            (string) file_get_contents($this->pluginsPath . '/rollback-me/src/Plugin.php'),
        );
    }

    public function testUpgradeRejectsMissingInstall(): void
    {
        $source = $this->makeSourcePlugin('missing-install');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Plugin not installed');
        $this->installer->upgradeFromSource($source, 'missing-install');
    }

    private function makeSourcePlugin(string $slug, string $version = '1.0.0', string $marker = 'CopyMe'): string
    {
        $parent = $this->root . '/sources';
        if (!is_dir($parent)) {
            mkdir($parent, 0775, true);
        }
        $dir = $parent . '/' . $slug;
        if (!is_dir($dir . '/src')) {
            mkdir($dir . '/src', 0775, true);
        }
        file_put_contents($dir . '/plugin.json', json_encode([
            'name' => 'Test ' . $slug,
            'slug' => $slug,
            'version' => $version,
            'min_latch_version' => '0.3.0',
            'hooks' => ['bootstrap'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($dir . '/src/Plugin.php', "<?php\n// {$marker}\n");

        return $dir;
    }

    private function createZipFromDirectory(string $sourceDir, string $zipPath): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('php-zip extension not available');
        }

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
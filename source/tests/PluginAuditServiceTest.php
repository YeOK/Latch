<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginAuditCache;
use Latch\Core\Plugins\PluginAuditService;
use Latch\Core\Plugins\PluginAuditor;
use Latch\Core\Plugins\PluginManifest;
use Latch\Core\Plugins\PluginManifestStore;
use Latch\Core\Plugins\PluginRegistry;
use Latch\Models\SettingRepository;
use Latch\Core\Database;
use PHPUnit\Framework\TestCase;

final class PluginAuditServiceTest extends TestCase
{
    private string $root;
    private string $pluginsPath;
    private string $cacheDir;
    private PluginAuditService $service;
    private PluginAuditor $auditor;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/latch-audit-svc-' . bin2hex(random_bytes(4));
        $this->pluginsPath = $this->root . '/plugins';
        $this->cacheDir = $this->root . '/storage/cache/plugin-audits';
        mkdir($this->pluginsPath, 0775, true);
        mkdir($this->cacheDir, 0775, true);

        $this->auditor = new PluginAuditor($this->root, $this->pluginsPath, $this->root . '/storage');
        $this->service = new PluginAuditService($this->auditor, new PluginAuditCache($this->cacheDir));
    }

    public function testUsesCacheWhenFingerprintUnchanged(): void
    {
        $manifest = $this->writePlugin('cached-plugin');

        $first = $this->service->getOrScan($manifest, false);
        $this->assertFalse($first['from_cache']);

        $second = $this->service->getOrScan($manifest, false);
        $this->assertTrue($second['from_cache']);
        $this->assertSame($first['report']->criticalCount(), $second['report']->criticalCount());
    }

    public function testSurvivesUnwritableCacheDirectory(): void
    {
        @chmod($this->cacheDir, 0555);
        $manifest = $this->writePlugin('no-cache-write');

        $result = $this->service->getOrScan($manifest, false);

        $this->assertFalse($result['from_cache']);
        $this->assertTrue($result['report']->passed());

        @chmod($this->cacheDir, 0775);
    }

    public function testIgnoredPluginsAreExcludedFromDiscovery(): void
    {
        $this->writePlugin('visible');
        $ignoredDir = $this->pluginsPath . '/hidden';
        mkdir($ignoredDir, 0775, true);
        $this->writePluginJson($ignoredDir, 'hidden');
        PluginManifestStore::setIgnored($ignoredDir, true);

        $dbPath = sys_get_temp_dir() . '/latch-audit-svc-db-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database($dbPath);
        $db->pdo()->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            INSERT INTO settings (key, value) VALUES ('enabled_plugins', '[\"hidden\"]');");
        $registry = new PluginRegistry($this->pluginsPath, new SettingRepository($db));

        $slugs = array_map(static fn (PluginManifest $m): string => $m->slug, $registry->discover());
        $this->assertSame(['visible'], $slugs);

        $stats = $this->service->auditDiscoveredPlugins($registry, false);
        $this->assertSame(1, $stats['scanned'] + $stats['cached']);
    }

    private function writePlugin(string $slug): PluginManifest
    {
        $dir = $this->pluginsPath . '/' . $slug;
        mkdir($dir, 0775, true);
        $this->writePluginJson($dir, $slug);

        return PluginManifest::fromDirectory($dir);
    }

    private function writePluginJson(string $dir, string $slug): void
    {
        file_put_contents($dir . '/plugin.json', json_encode([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'version' => '1.0.0',
            'min_latch_version' => '0.3.0',
            'hooks' => ['layout.footer'],
            'permissions' => ['filesystem' => [], 'network' => [], 'config' => []],
        ], JSON_THROW_ON_ERROR));
        mkdir($dir . '/src', 0775, true);
        file_put_contents($dir . '/src/Plugin.php', "<?php\nnamespace Latch\\Plugins\\" . PluginManifest::studlySlug($slug) . ";\nclass Plugin {}\n");
    }
}
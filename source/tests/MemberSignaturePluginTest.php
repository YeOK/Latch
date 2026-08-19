<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Core\Plugins\PluginAuditor;
use Latch\Core\Plugins\PluginDatabase;
use Latch\Core\Plugins\PluginManifest;
use Latch\Plugins\MemberSignature\Store;
use PHPUnit\Framework\TestCase;

final class MemberSignaturePluginTest extends TestCase
{
    private string $pluginDir;

    protected function setUp(): void
    {
        $root = CatalogPath::root();
        if (!is_dir($root . '/member-signature')) {
            $this->markTestSkipped('member-signature plugin not present in Latch-plugins');
        }

        $this->pluginDir = $root . '/member-signature';
        $manifest = PluginManifest::fromDirectory($this->pluginDir);
        $prefix = 'Latch\\Plugins\\' . PluginManifest::studlySlug('member-signature') . '\\';
        $baseDir = $manifest->pluginDir . '/src/';
        spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });
    }

    public function testPluginPassesAudit(): void
    {
        $root = dirname(__DIR__);
        $auditor = new PluginAuditor($root, $root . '/plugins', $root . '/storage');
        $report = $auditor->auditPath($this->pluginDir);
        $this->assertTrue($report->enableAllowed(), $report->toHuman());
    }

    public function testStoreRoundTrip(): void
    {
        $path = sys_get_temp_dir() . '/latch-sig-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database($path);
        $db->pdo()->exec((string) file_get_contents($this->pluginDir . '/migrations/001_profiles.sql'));
        $store = new Store(new PluginDatabase($db, 'member-signature', $path));
        $store->save(7, 'Mod', "hello\nworld");
        $row = $store->get(7);
        $this->assertSame('Mod', $row['title']);
        $this->assertSame("hello\nworld", $row['signature']);
        @unlink($path);
    }
}

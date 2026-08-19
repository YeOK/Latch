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
use Latch\Plugins\InviteOnly\InviteStore;
use PHPUnit\Framework\TestCase;

final class InviteOnlyPluginTest extends TestCase
{
    private string $pluginDir;

    protected function setUp(): void
    {
        $root = CatalogPath::root();
        if (!is_dir($root . '/invite-only')) {
            $this->markTestSkipped('invite-only plugin not present in Latch-plugins');
        }

        $this->pluginDir = $root . '/invite-only';
        $manifest = PluginManifest::fromDirectory($this->pluginDir);
        $prefix = 'Latch\\Plugins\\' . PluginManifest::studlySlug('invite-only') . '\\';
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

    public function testConsumeIsSingleUse(): void
    {
        $path = sys_get_temp_dir() . '/latch-inv-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database($path);
        $db->pdo()->exec((string) file_get_contents($this->pluginDir . '/migrations/001_codes.sql'));
        $store = new InviteStore(new PluginDatabase($db, 'invite-only', $path));
        $code = $store->generate(1, 'test');
        $this->assertNotNull($code);
        $this->assertTrue($store->isUnused((string) $code));
        $this->assertTrue($store->consume((string) $code, 0));
        $this->assertFalse($store->isUnused((string) $code));
        $this->assertFalse($store->consume((string) $code, 0));
        $store->release((string) $code);
        $this->assertTrue($store->isUnused((string) $code));
        $this->assertTrue($store->consume((string) $code, 0));
        $store->attachUser((string) $code, 9);
        $row = $db->pdo()->query('SELECT used_by FROM invite_codes')->fetch();
        $this->assertSame(9, (int) $row['used_by']);
        @unlink($path);
    }
}

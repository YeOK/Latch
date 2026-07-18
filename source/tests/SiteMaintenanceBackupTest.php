<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Support\SiteMaintenance;
use Latch\Support\SiteRestore;
use Latch\Support\SqliteIntegrity;
use PHPUnit\Framework\TestCase;

final class SiteMaintenanceBackupTest extends TestCase
{
    private string $root;
    private string $storagePath;
    private string $dbPath;

    protected function setUp(): void
    {
        if (!defined('LATCH_ROOT')) {
            define('LATCH_ROOT', dirname(__DIR__));
        }

        $this->root = sys_get_temp_dir() . '/latch-backup-test-' . bin2hex(random_bytes(4));
        $this->storagePath = $this->root . '/storage';
        $this->dbPath = $this->storagePath . '/database/latch.sqlite';
        mkdir($this->storagePath . '/database', 0775, true);
        mkdir($this->root . '/config', 0775, true);

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('CREATE TABLE sample (id INTEGER PRIMARY KEY); INSERT INTO sample VALUES (1);');
        file_put_contents($this->root . '/config/local.php', "<?php return [];\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testCreateBackupSplitsCoreAndPlugins(): void
    {
        $pluginDb = $this->storagePath . '/plugins/spam-bridge/plugin.sqlite';
        mkdir(dirname($pluginDb), 0775, true);
        $pdo = new \PDO('sqlite:' . $pluginDb);
        $pdo->exec('CREATE TABLE spam_log (id INTEGER PRIMARY KEY); INSERT INTO spam_log VALUES (42);');
        file_put_contents($this->storagePath . '/plugins/spam-bridge/settings.json', '{"enabled":true}');

        $result = SiteMaintenance::createBackup(
            $this->storagePath,
            $this->dbPath,
            $this->root . '/config/local.php',
        );

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertNotNull($result['path']);
        $this->assertFileExists($result['path']);
        $this->assertSame(['core', 'plugins'], $result['parts']);

        $meta = SiteRestore::describeArchive($result['path']);
        $this->assertSame('split', $meta['format']);
        $this->assertSame(['core', 'plugins'], $meta['parts']);

        $extractDir = $this->root . '/extract';
        mkdir($extractDir, 0775, true);
        exec('tar -xzf ' . escapeshellarg($result['path']) . ' -C ' . escapeshellarg($extractDir), $out, $code);
        $this->assertSame(0, $code);
        $this->assertFileExists($extractDir . '/core.tar.gz');
        $this->assertFileExists($extractDir . '/plugins.tar.gz');

        $coreDir = $extractDir . '/core-inner';
        mkdir($coreDir, 0775, true);
        exec('tar -xzf ' . escapeshellarg($extractDir . '/core.tar.gz') . ' -C ' . escapeshellarg($coreDir), $o2, $c2);
        $this->assertSame(0, $c2);
        $extractedDb = $coreDir . '/storage/database/latch.sqlite';
        $this->assertFileExists($extractedDb);
        $report = SqliteIntegrity::run($extractedDb);
        $this->assertTrue($report['ok']);

        $plugDir = $extractDir . '/plug-inner';
        mkdir($plugDir, 0775, true);
        exec('tar -xzf ' . escapeshellarg($extractDir . '/plugins.tar.gz') . ' -C ' . escapeshellarg($plugDir), $o3, $c3);
        $this->assertSame(0, $c3);
        $this->assertFileExists($plugDir . '/storage/plugins/spam-bridge/plugin.sqlite');
        $this->assertFileExists($plugDir . '/storage/plugins/spam-bridge/settings.json');
        $val = (string) (new \PDO('sqlite:' . $plugDir . '/storage/plugins/spam-bridge/plugin.sqlite'))
            ->query('SELECT id FROM spam_log')->fetchColumn();
        $this->assertSame('42', $val);
    }

    public function testCoreOnlyBackupOmitsPlugins(): void
    {
        mkdir($this->storagePath . '/plugins/x', 0775, true);
        file_put_contents($this->storagePath . '/plugins/x/settings.json', '{}');

        $result = SiteMaintenance::createBackup(
            $this->storagePath,
            $this->dbPath,
            $this->root . '/config/local.php',
            ['core' => true, 'plugins' => false],
        );

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(['core'], $result['parts']);
        $meta = SiteRestore::describeArchive((string) $result['path']);
        $this->assertSame(['core'], $meta['parts']);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->removeTree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}

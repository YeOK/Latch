<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Support\SiteMaintenance;
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

    public function testCreateBackupProducesIntegrityCleanExtract(): void
    {
        $result = SiteMaintenance::createBackup(
            $this->storagePath,
            $this->dbPath,
            $this->root . '/config/local.php',
        );

        $this->assertTrue($result['ok'], $result['message']);
        $archive = $result['path'];
        $this->assertNotNull($archive);
        $this->assertFileExists($archive);

        $extractDir = $this->root . '/extract';
        mkdir($extractDir, 0775, true);
        exec('tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($extractDir), $out, $code);
        $this->assertSame(0, $code);

        $extractedDb = $extractDir . '/storage/database/latch.sqlite';
        $this->assertFileExists($extractedDb);

        $report = SqliteIntegrity::run($extractedDb);
        $this->assertTrue($report['ok']);
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
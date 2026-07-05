<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Support\SiteLock;
use Latch\Support\SiteRestore;
use Latch\Support\SqliteIntegrity;
use PHPUnit\Framework\TestCase;

final class SiteRestoreTest extends TestCase
{
    private string $root;
    private string $storagePath;
    private string $dbPath;
    private string $backupDir;

    protected function setUp(): void
    {
        if (!defined('LATCH_ROOT')) {
            define('LATCH_ROOT', dirname(__DIR__));
        }

        $this->root = sys_get_temp_dir() . '/latch-restore-test-' . bin2hex(random_bytes(4));
        $this->storagePath = $this->root . '/storage';
        $this->backupDir = $this->storagePath . '/backups';
        $this->dbPath = $this->storagePath . '/database/latch.sqlite';

        mkdir($this->storagePath . '/database', 0775, true);
        mkdir($this->backupDir, 0775, true);
        mkdir($this->root . '/config', 0775, true);

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('CREATE TABLE marker (value TEXT); INSERT INTO marker VALUES ("live");');
        file_put_contents($this->root . '/config/local.php', "<?php return ['site' => ['url' => 'http://test']];\n");
    }

    protected function tearDown(): void
    {
        SiteLock::disable($this->storagePath);
        $this->removeTree($this->root);
    }

    public function testListBackupsReadsArchiveContents(): void
    {
        $archive = $this->createArchive('backup');
        $backups = SiteRestore::listBackups($this->storagePath);

        $this->assertCount(1, $backups);
        $this->assertSame(basename($archive), $backups[0]['name']);
        $this->assertContains('storage/database/latch.sqlite', $backups[0]['contents']);
    }

    public function testRestoreRequiresLock(): void
    {
        $archive = $this->createArchive('backup');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(3);

        SiteRestore::restore([
            'storage_path' => $this->storagePath,
            'source_root' => $this->root,
            'db_path' => $this->dbPath,
            'local_config_path' => $this->root . '/config/local.php',
            'archive' => $archive,
        ]);
    }

    public function testRestoreLatestReplacesDatabase(): void
    {
        $archive = $this->createArchive('good');
        SiteLock::enable($this->storagePath, 'test', 'phpunit');

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('UPDATE marker SET value = "corrupt-live"');

        $result = SiteRestore::restore([
            'storage_path' => $this->storagePath,
            'source_root' => $this->root,
            'db_path' => $this->dbPath,
            'local_config_path' => $this->root . '/config/local.php',
            'archive' => $archive,
        ]);

        $this->assertTrue($result['ok']);
        $value = (string) (new \PDO('sqlite:' . $this->dbPath))->query('SELECT value FROM marker')->fetchColumn();
        $this->assertSame('good', $value);

        $check = SqliteIntegrity::run($this->dbPath);
        $this->assertTrue($check['ok']);
    }

    private function createArchive(string $markerValue): string
    {
        $staging = $this->root . '/stage-' . bin2hex(random_bytes(3));
        mkdir($staging . '/storage/database', 0775, true);

        $backupDb = $staging . '/storage/database/latch.sqlite';
        copy($this->dbPath, $backupDb);
        $pdo = new \PDO('sqlite:' . $backupDb);
        $pdo->exec('UPDATE marker SET value = ' . $pdo->quote($markerValue));

        $archive = $this->backupDir . '/latch-backup-test-' . bin2hex(random_bytes(3)) . '.tar.gz';
        $cmd = 'tar -czf ' . escapeshellarg($archive)
            . ' -C ' . escapeshellarg($staging) . ' storage/database/latch.sqlite'
            . ' -C ' . escapeshellarg($this->root) . ' config/local.php';
        exec($cmd, $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));

        $this->removeTree($staging);

        return $archive;
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
<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Support\SiteLock;
use Latch\Support\SiteMaintenance;
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

    public function testListBackupsReadsLegacyArchiveContents(): void
    {
        $archive = $this->createLegacyArchive('backup');
        $backups = SiteRestore::listBackups($this->storagePath);

        $this->assertCount(1, $backups);
        $this->assertSame(basename($archive), $backups[0]['name']);
        $this->assertSame('legacy', $backups[0]['format']);
        $this->assertContains('storage/database/latch.sqlite', $backups[0]['contents']);
    }

    public function testRestoreRequiresLock(): void
    {
        $archive = $this->createLegacyArchive('backup');

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

    public function testRestoreLatestReplacesDatabaseLegacy(): void
    {
        $archive = $this->createLegacyArchive('good');
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

    public function testSplitBackupCoreOnlyLeavesPluginsUntouched(): void
    {
        $pluginPath = $this->storagePath . '/plugins/evil/settings.json';
        mkdir(dirname($pluginPath), 0775, true);
        file_put_contents($pluginPath, '{"evil":true}');
        $pluginDb = $this->storagePath . '/plugins/evil/plugin.sqlite';
        (new \PDO('sqlite:' . $pluginDb))->exec('CREATE TABLE t (id INTEGER); INSERT INTO t VALUES (1);');

        $result = SiteMaintenance::createBackup(
            $this->storagePath,
            $this->dbPath,
            $this->root . '/config/local.php',
        );
        $this->assertTrue($result['ok'], $result['message']);
        $archive = (string) $result['path'];

        // Simulate bad plugin state after backup
        file_put_contents($pluginPath, '{"evil":"worse"}');
        (new \PDO('sqlite:' . $this->dbPath))->exec('UPDATE marker SET value = "broken"');

        SiteLock::enable($this->storagePath, 'test', 'phpunit');
        $restore = SiteRestore::restore([
            'storage_path' => $this->storagePath,
            'source_root' => $this->root,
            'db_path' => $this->dbPath,
            'local_config_path' => $this->root . '/config/local.php',
            'archive' => $archive,
            'core_only' => true,
        ]);

        $this->assertTrue($restore['ok'], $restore['message']);
        $this->assertSame(['core'], $restore['parts']);
        $value = (string) (new \PDO('sqlite:' . $this->dbPath))->query('SELECT value FROM marker')->fetchColumn();
        $this->assertSame('live', $value);
        // Plugins left as-is (worse) so operator can disable without replaying backup plugin data
        $this->assertSame('{"evil":"worse"}', (string) file_get_contents($pluginPath));
    }

    public function testSplitBackupFullRestoresPlugins(): void
    {
        $pluginPath = $this->storagePath . '/plugins/good/settings.json';
        mkdir(dirname($pluginPath), 0775, true);
        file_put_contents($pluginPath, '{"v":1}');
        $pluginDb = $this->storagePath . '/plugins/good/plugin.sqlite';
        (new \PDO('sqlite:' . $pluginDb))->exec('CREATE TABLE t (id INTEGER); INSERT INTO t VALUES (7);');

        $result = SiteMaintenance::createBackup(
            $this->storagePath,
            $this->dbPath,
            $this->root . '/config/local.php',
        );
        $this->assertTrue($result['ok'], $result['message']);

        file_put_contents($pluginPath, '{"v":999}');
        (new \PDO('sqlite:' . $pluginDb))->exec('DELETE FROM t; INSERT INTO t VALUES (0);');

        SiteLock::enable($this->storagePath, 'test', 'phpunit');
        $restore = SiteRestore::restore([
            'storage_path' => $this->storagePath,
            'source_root' => $this->root,
            'db_path' => $this->dbPath,
            'local_config_path' => $this->root . '/config/local.php',
            'archive' => (string) $result['path'],
        ]);

        $this->assertTrue($restore['ok'], $restore['message']);
        $this->assertEqualsCanonicalizing(['core', 'plugins'], $restore['parts']);
        $this->assertSame('{"v":1}', (string) file_get_contents($pluginPath));
        $id = (string) (new \PDO('sqlite:' . $pluginDb))->query('SELECT id FROM t')->fetchColumn();
        $this->assertSame('7', $id);
    }

    public function testPluginsOnlyRestore(): void
    {
        $pluginPath = $this->storagePath . '/plugins/good/settings.json';
        mkdir(dirname($pluginPath), 0775, true);
        file_put_contents($pluginPath, '{"v":1}');

        $result = SiteMaintenance::createBackup(
            $this->storagePath,
            $this->dbPath,
            $this->root . '/config/local.php',
        );
        $this->assertTrue($result['ok'], $result['message']);

        file_put_contents($pluginPath, '{"v":lost}');
        (new \PDO('sqlite:' . $this->dbPath))->exec('UPDATE marker SET value = "must-stay"');

        SiteLock::enable($this->storagePath, 'test', 'phpunit');
        $restore = SiteRestore::restore([
            'storage_path' => $this->storagePath,
            'source_root' => $this->root,
            'db_path' => $this->dbPath,
            'local_config_path' => $this->root . '/config/local.php',
            'archive' => (string) $result['path'],
            'plugins_only' => true,
        ]);

        $this->assertTrue($restore['ok'], $restore['message']);
        $this->assertSame(['plugins'], $restore['parts']);
        $this->assertSame('{"v":1}', (string) file_get_contents($pluginPath));
        $value = (string) (new \PDO('sqlite:' . $this->dbPath))->query('SELECT value FROM marker')->fetchColumn();
        $this->assertSame('must-stay', $value);
    }

    private function createLegacyArchive(string $markerValue): string
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

<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginDatabaseManager;
use Latch\Core\Plugins\PluginManifest;
use Latch\Core\Plugins\PluginMigrator;
use Latch\Core\Database;
use PHPUnit\Framework\TestCase;

final class PluginDatabaseTest extends TestCase
{
    private string $root;
    private string $pluginsPath;
    private string $storagePath;
    private PluginDatabaseManager $manager;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/latch-plugin-db-' . bin2hex(random_bytes(4));
        $this->pluginsPath = $this->root . '/plugins';
        $this->storagePath = $this->root . '/storage';
        mkdir($this->pluginsPath, 0775, true);
        mkdir($this->storagePath, 0775, true);
        $this->manager = new PluginDatabaseManager($this->storagePath);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
    }

    public function testMigrateAppliesSqlFilesInOrder(): void
    {
        $manifest = $this->makePluginWithMigrations('db-test', [
            '001_first.sql' => 'CREATE TABLE first_table (id INTEGER PRIMARY KEY);',
            '002_second.sql' => 'CREATE TABLE second_table (id INTEGER PRIMARY KEY);',
        ]);

        $applied = $this->manager->migrate($manifest);

        $this->assertSame(2, $applied);
        $this->assertFileExists($this->manager->databasePath('db-test'));

        $pdo = $this->manager->open($manifest)?->pdo();
        $this->assertNotNull($pdo);

        $first = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'first_table'")->fetchColumn();
        $second = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'second_table'")->fetchColumn();
        $this->assertSame('first_table', $first);
        $this->assertSame('second_table', $second);

        $versions = $pdo->query('SELECT version FROM plugin_migrations ORDER BY version')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['001_first.sql', '002_second.sql'], $versions);

        $schemaVersion = $pdo->query("SELECT value FROM plugin_meta WHERE key = 'schema_version'")->fetchColumn();
        $this->assertSame('002_second.sql', $schemaVersion);
    }

    public function testMigrateIsIdempotent(): void
    {
        $manifest = $this->makePluginWithMigrations('idempotent', [
            '001_meta.sql' => 'CREATE TABLE event_log (id INTEGER PRIMARY KEY, message TEXT NOT NULL);',
        ]);

        $this->assertSame(1, $this->manager->migrate($manifest));
        $this->assertSame(0, $this->manager->migrate($manifest));
        $this->assertSame(0, $this->manager->pendingCount($manifest));
    }

    public function testSpamLogSchemaMigration(): void
    {
        $manifest = $this->makePluginWithMigrations('spam-bridge', [
            '001_spam_log.sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS spam_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    kind TEXT NOT NULL,
    provider TEXT NOT NULL,
    user_id INTEGER,
    post_id INTEGER,
    reason TEXT NOT NULL,
    payload TEXT
);
CREATE INDEX IF NOT EXISTS idx_spam_log_created_at ON spam_log(created_at);
SQL,
        ], databaseEnabled: true);

        $this->manager->migrate($manifest);

        $pdo = $this->manager->open($manifest)?->pdo();
        $this->assertNotNull($pdo);

        $table = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'spam_log'")->fetchColumn();
        $this->assertSame('spam_log', $table);

        $pdo->prepare(
            'INSERT INTO spam_log (created_at, kind, provider, user_id, post_id, reason, payload)
             VALUES (:created_at, :kind, :provider, :user_id, :post_id, :reason, :payload)'
        )->execute([
            'created_at' => gmdate('c'),
            'kind' => 'post',
            'provider' => 'akismet',
            'user_id' => 1,
            'post_id' => null,
            'reason' => 'spam',
            'payload' => '{"score":0.99}',
        ]);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM spam_log')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testSkipsDatabaseWhenManifestDisabled(): void
    {
        $manifest = $this->makePluginWithMigrations('no-db', [
            '001_should_not_run.sql' => 'CREATE TABLE should_not_exist (id INTEGER PRIMARY KEY);',
        ], databaseEnabled: false);

        $this->assertSame(0, $this->manager->migrate($manifest));
        $this->assertFileDoesNotExist($this->manager->databasePath('no-db'));
        $this->assertNull($this->manager->open($manifest));
    }

    public function testManifestParsesDatabaseFlag(): void
    {
        $dir = $this->pluginsPath . '/manifest-db';
        mkdir($dir . '/migrations', 0775, true);
        file_put_contents($dir . '/plugin.json', json_encode([
            'name' => 'Manifest DB',
            'slug' => 'manifest-db',
            'version' => '1.0.0',
            'min_latch_version' => '0.4.0.0',
            'hooks' => ['layout.footer'],
            'database' => ['enabled' => true],
        ], JSON_THROW_ON_ERROR));

        $manifest = PluginManifest::fromDirectory($dir);

        $this->assertTrue($manifest->databaseEnabled);
    }

    public function testMigratorRejectsEmptyMigrationFile(): void
    {
        $dir = $this->pluginsPath . '/empty-migration';
        mkdir($dir . '/migrations', 0775, true);
        file_put_contents($dir . '/migrations/001_empty.sql', "   \n");

        $dbPath = $this->storagePath . '/plugins/empty-migration/plugin.sqlite';
        mkdir(dirname($dbPath), 0775, true);

        $migrator = new PluginMigrator(new Database($dbPath), $dir . '/migrations');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Empty plugin migration');
        $migrator->migrate();
    }

    /**
     * @param array<string, string> $migrations
     */
    private function makePluginWithMigrations(
        string $slug,
        array $migrations,
        bool $databaseEnabled = true,
    ): PluginManifest {
        $dir = $this->pluginsPath . '/' . $slug;
        mkdir($dir . '/migrations', 0775, true);

        foreach ($migrations as $filename => $sql) {
            file_put_contents($dir . '/migrations/' . $filename, $sql . "\n");
        }

        file_put_contents($dir . '/plugin.json', json_encode([
            'name' => 'Test ' . $slug,
            'slug' => $slug,
            'version' => '1.0.0',
            'min_latch_version' => '0.4.0.0',
            'hooks' => ['layout.footer'],
            'database' => ['enabled' => $databaseEnabled],
        ], JSON_THROW_ON_ERROR));

        return PluginManifest::fromDirectory($dir);
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
<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Core\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-migrator-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testFreshInstallAppliesMigrationsInOrder(): void
    {
        $migrationsPath = LATCH_ROOT . '/database/migrations';
        $db = new Database($this->dbPath);
        $migrator = new Migrator($db, $migrationsPath);

        $applied = $migrator->migrate();

        $this->assertGreaterThan(0, $applied);
        $this->assertSame(0, $migrator->pendingCount());

        $pdo = $db->pdo();
        $users = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
        $this->assertSame('users', $users);

        $securityApplied = (bool) $pdo
            ->query("SELECT 1 FROM schema_migrations WHERE version = '001a_security.sql'")
            ->fetchColumn();
        $this->assertTrue($securityApplied);
    }

    public function testLegacySecurityMigrationAliasIsNotReapplied(): void
    {
        $migrationsPath = LATCH_ROOT . '/database/migrations';
        $db = new Database($this->dbPath);
        $pdo = $db->pdo();

        $pdo->exec(file_get_contents($migrationsPath . '/001_initial.sql'));
        $pdo->exec(file_get_contents($migrationsPath . '/001a_security.sql'));
        $pdo->exec(
            "INSERT INTO schema_migrations (version, applied_at) VALUES ('001.5_security.sql', '2026-01-01T00:00:00+00:00')"
        );

        $migrator = new Migrator($db, $migrationsPath);
        $applied = $migrator->migrate();

        $this->assertSame(0, $migrator->pendingCount());

        $newNameApplied = (bool) $pdo
            ->query("SELECT 1 FROM schema_migrations WHERE version = '001a_security.sql'")
            ->fetchColumn();
        $legacyApplied = (bool) $pdo
            ->query("SELECT 1 FROM schema_migrations WHERE version = '001.5_security.sql'")
            ->fetchColumn();

        $this->assertFalse($newNameApplied);
        $this->assertTrue($legacyApplied);
    }
}
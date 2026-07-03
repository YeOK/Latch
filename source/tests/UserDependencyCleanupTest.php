<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Support\UserDependencyCleanup;
use PHPUnit\Framework\TestCase;

final class UserDependencyCleanupTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private UserDependencyCleanup $cleanup;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-deps-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $this->cleanup = new UserDependencyCleanup();
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testPruneOrphansRemovesRowsWithMissingUser(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY);
             CREATE TABLE email_verifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                token_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                verified_at TEXT,
                created_at TEXT NOT NULL
             );
             INSERT INTO users (id) VALUES (1);
             INSERT INTO email_verifications (user_id, email, token_hash, expires_at, created_at)
             VALUES (1, "a@test", "x", "2099-01-01T00:00:00+00:00", "2026-01-01"),
                    (99, "orphan@test", "y", "2099-01-01T00:00:00+00:00", "2026-01-01");'
        );

        $removed = $this->cleanup->pruneOrphans($pdo);

        $this->assertSame(['email_verifications' => 1], $removed);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM email_verifications')->fetchColumn());
        $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
    }

    public function testDeleteForUserClearsDependenciesBeforeUserDelete(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec(
            'PRAGMA foreign_keys = ON;
             CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                email TEXT,
                password_hash TEXT,
                role TEXT,
                created_at TEXT
             );
             CREATE TABLE email_verifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                token_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                verified_at TEXT,
                created_at TEXT NOT NULL
             );
             CREATE TABLE password_resets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL
             );
             INSERT INTO users (id, username, email, password_hash, role, created_at)
             VALUES (2, "spammer", "s@test", "x", "member", "2026-01-01");
             INSERT INTO email_verifications (user_id, email, token_hash, expires_at, created_at)
             VALUES (2, "s@test", "a", "2099-01-01T00:00:00+00:00", "2026-01-01");
             INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
             VALUES (2, "b", "2099-01-01T00:00:00+00:00", "2026-01-01");'
        );

        $this->cleanup->deleteForUser($pdo, 2);
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => 2]);

        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM users WHERE id = 2')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM email_verifications')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn());
    }
}
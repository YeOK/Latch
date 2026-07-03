<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Models\UserRepository;
use PHPUnit\Framework\TestCase;

final class UserRepositoryPurgeTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-purge-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            'PRAGMA foreign_keys = ON;
             CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL,
                created_at TEXT NOT NULL
             );
             CREATE TABLE email_verifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                email TEXT NOT NULL,
                token_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                verified_at TEXT,
                created_at TEXT NOT NULL
             );
             CREATE TABLE password_resets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT,
                created_at TEXT NOT NULL
             );
             CREATE TABLE user_warnings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                issued_by INTEGER NOT NULL,
                reason TEXT NOT NULL,
                created_at TEXT NOT NULL
             );
             CREATE TABLE boards (
                id INTEGER PRIMARY KEY,
                slug TEXT,
                name TEXT,
                acl_read TEXT DEFAULT "guest"
             );
             CREATE TABLE topics (
                id INTEGER PRIMARY KEY,
                board_id INTEGER,
                user_id INTEGER,
                deleted_at TEXT
             );
             CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                topic_id INTEGER,
                user_id INTEGER,
                deleted_at TEXT,
                quarantined_at TEXT,
                approval_status TEXT DEFAULT "approved"
             );
             INSERT INTO boards (id, slug, name) VALUES (1, "general", "General");'
        );
        $pdo->exec(
            "INSERT INTO users (id, username, email, password_hash, role, created_at)
             VALUES (1, 'founder', 'f@test', 'x', 'admin', '2026-01-01'),
                    (2, 'spammer', 's@test', 'x', 'member', '2026-01-01');
             INSERT INTO email_verifications (user_id, email, token_hash, expires_at, created_at)
             VALUES (2, 's@test', 'abc', '2099-01-01T00:00:00+00:00', '2026-01-01');
             INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
             VALUES (2, 'def', '2099-01-01T00:00:00+00:00', '2026-01-01');
             INSERT INTO user_warnings (user_id, issued_by, reason, created_at)
             VALUES (2, 1, 'spam', '2026-01-01');"
        );

        $this->users = new UserRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testPurgeRemovesDependentRows(): void
    {
        $this->users->purge(2);

        $pdo = $this->db->pdo();
        $this->assertNull($this->users->findById(2));
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM email_verifications')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM user_warnings')->fetchColumn());
        $this->assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
    }

    public function testPurgeRejectsFounder(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->users->purge(1);
    }
}
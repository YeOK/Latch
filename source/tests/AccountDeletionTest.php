<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Models\PostRepository;
use Latch\Models\UserRepository;
use Latch\Support\DeletedAuthorSql;
use Latch\Support\SqliteIntegrity;
use PHPUnit\Framework\TestCase;

final class AccountDeletionTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private UserRepository $users;
    private PostRepository $posts;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-account-delete-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            <<<'SQL'
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                email TEXT,
                password_hash TEXT,
                role TEXT NOT NULL DEFAULT 'member',
                created_at TEXT,
                banned_at TEXT,
                banned_until TEXT,
                ban_reason TEXT,
                deleted_at TEXT
            );
            CREATE TABLE user_warnings (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                reason TEXT,
                issued_by INTEGER,
                created_at TEXT
            );
            CREATE TABLE boards (id INTEGER PRIMARY KEY, slug TEXT, name TEXT);
            CREATE TABLE topics (
                id INTEGER PRIMARY KEY,
                board_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL REFERENCES users(id),
                title TEXT,
                slug TEXT,
                created_at TEXT,
                last_post_at TEXT,
                deleted_at TEXT
            );
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                topic_id INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id),
                body TEXT,
                created_at TEXT,
                deleted_at TEXT,
                approval_status TEXT NOT NULL DEFAULT 'approved'
            );
            INSERT INTO boards (id, slug, name) VALUES (1, 'news', 'News');
            INSERT INTO users (id, username, email, password_hash, role, created_at) VALUES
                (1, 'founder', 'founder@test', 'hash', 'admin', '2026-01-01T00:00:00+00:00'),
                (2, 'member', 'member@test', 'hash', 'member', '2026-01-02T00:00:00+00:00'),
                (3, 'banned', 'banned@test', 'hash', 'member', '2026-01-03T00:00:00+00:00');
            SQL
        );
        $this->users = new UserRepository($this->db);
        $this->posts = new PostRepository($this->db);
        $this->users->ban(3, null, 'Spam');
        $this->db->pdo()->exec(
            "INSERT INTO topics (id, board_id, user_id, title, slug, created_at, last_post_at)
             VALUES (10, 1, 2, 'Thread', 'thread', '2026-01-02T00:00:00+00:00', '2026-01-02T00:00:00+00:00');
             INSERT INTO posts (id, topic_id, user_id, body, created_at)
             VALUES (100, 10, 2, 'Hello', '2026-01-02T00:00:00+00:00');"
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testAnonymiseSetsDeletedAtNotBannedAt(): void
    {
        $this->users->anonymise(2);
        $user = $this->users->findById(2);

        $this->assertNotNull($user);
        $this->assertSame('deleted_2', $user['username']);
        $this->assertSame('deleted_2@deleted.local', $user['email']);
        $this->assertNotNull($user['deleted_at']);
        $this->assertNull($user['banned_at']);
        $this->assertTrue($this->users->isDeleted($user));
        $this->assertFalse($this->users->isBanned($user));
    }

    public function testDeletedFilterListsSelfDeletedAccounts(): void
    {
        $this->users->anonymise(2);

        $deleted = $this->users->listAdmin('deleted', '', 1);
        $banned = $this->users->listAdmin('banned', '', 1);
        $members = $this->users->listAdmin('members', '', 1);

        $this->assertSame(1, $deleted['total']);
        $this->assertSame(2, (int) $deleted['users'][0]['id']);
        $this->assertSame(1, $banned['total']);
        $this->assertSame(3, (int) $banned['users'][0]['id']);
        $this->assertSame(0, $members['total']);
    }

    public function testCountBannedExcludesDeletedAccounts(): void
    {
        $this->users->anonymise(2);

        $this->assertSame(1, $this->users->countBanned());
        $this->assertSame(1, $this->users->countDeleted());
    }

    public function testPurgeExpiredDeletedRemovesUserRowButKeepsPosts(): void
    {
        $expired = gmdate('c', time() - (31 * 86400));
        $this->db->pdo()->prepare(
            'UPDATE users SET username = :username, email = :email, password_hash = :hash, deleted_at = :deleted_at WHERE id = 2'
        )->execute([
            'username' => 'deleted_2',
            'email' => 'deleted_2@deleted.local',
            'hash' => 'x',
            'deleted_at' => $expired,
        ]);

        $this->assertSame(1, $this->users->purgeExpiredDeleted(30));
        $this->assertNull($this->users->findById(2));
        $this->assertSame(1, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM posts WHERE id = 100')->fetchColumn());

        $listed = $this->posts->listByTopic(10, false, null, true);
        $this->assertCount(1, $listed);
        $this->assertSame(DeletedAuthorSql::LABEL, $listed[0]['author_name']);

        $integrity = SqliteIntegrity::run($this->dbPath);
        $this->assertTrue($integrity['ok'], (string) json_encode($integrity['checks']));
    }

    public function testPurgeExpiredDeletedSkipsRecentAccounts(): void
    {
        $this->users->anonymise(2);

        $this->assertSame(0, $this->users->purgeExpiredDeleted(30));
        $this->assertNotNull($this->users->findById(2));
    }
}
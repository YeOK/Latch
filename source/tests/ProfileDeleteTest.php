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
use Latch\Models\SettingRepository;
use Latch\Models\TopicRepository;
use Latch\Models\UserRepository;
use Latch\Models\UserSessionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the account-delete path used by ProfileController (repository layer).
 */
final class ProfileDeleteTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private UserRepository $users;
    private PostRepository $posts;
    private TopicRepository $topics;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-profile-delete-' . bin2hex(random_bytes(4)) . '.sqlite';
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
                deleted_at TEXT
            );
            CREATE TABLE user_sessions (
                id TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                fingerprint TEXT NOT NULL DEFAULT '',
                ip_address TEXT NOT NULL DEFAULT '',
                user_agent TEXT NOT NULL DEFAULT '',
                last_seen_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                revoked_at TEXT
            );
            CREATE TABLE email_change_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                new_email TEXT NOT NULL,
                token_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT,
                created_at TEXT NOT NULL
            );
            CREATE TABLE boards (id INTEGER PRIMARY KEY, slug TEXT, name TEXT);
            CREATE TABLE topics (
                id INTEGER PRIMARY KEY,
                board_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                title TEXT,
                slug TEXT,
                created_at TEXT,
                last_post_at TEXT,
                deleted_at TEXT
            );
            CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                topic_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                body TEXT,
                created_at TEXT,
                updated_at TEXT,
                deleted_at TEXT,
                approval_status TEXT NOT NULL DEFAULT 'approved'
            );
            CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            INSERT INTO boards (id, slug, name) VALUES (1, 'general', 'General');
            INSERT INTO users (id, username, email, password_hash, role, created_at) VALUES
                (1, 'founder', 'founder@test', 'hash', 'admin', '2026-01-01T00:00:00+00:00'),
                (2, 'member', 'member@test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'member', '2026-01-02T00:00:00+00:00');
            INSERT INTO topics (id, board_id, user_id, title, slug, created_at, last_post_at)
            VALUES (10, 1, 2, 'My thread', 'my-thread', '2026-01-02T00:00:00+00:00', '2026-01-02T00:00:00+00:00');
            INSERT INTO posts (id, topic_id, user_id, body, created_at)
            VALUES (100, 10, 2, 'Hello world', '2026-01-02T00:00:00+00:00');
            INSERT INTO user_sessions (id, user_id, last_seen_at, created_at)
            VALUES ('sess-1', 2, '2026-01-02T00:00:00+00:00', '2026-01-02T00:00:00+00:00');
            INSERT INTO email_change_requests (user_id, new_email, token_hash, expires_at, created_at)
            VALUES (2, 'new@test', 'hash', '2099-01-01T00:00:00+00:00', '2026-01-02T00:00:00+00:00');
            SQL
        );

        $this->users = new UserRepository($this->db);
        $this->posts = new PostRepository($this->db);
        $this->topics = new TopicRepository($this->db, $this->posts);
        (new SettingRepository($this->db))->setBool('anonymise_posts_on_delete', true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testSelfDeleteAnonymisesUserAndContent(): void
    {
        $userId = 2;
        $topicIds = array_values(array_unique(array_merge(
            $this->posts->anonymiseContentByUser($userId),
            $this->topics->anonymiseTitlesByUser($userId),
        )));

        $this->users->anonymise($userId);
        (new UserSessionRepository($this->db))->revokeAllForUser($userId);

        $user = $this->users->findById($userId);
        $this->assertNotNull($user);
        $this->assertTrue($this->users->isDeleted($user));
        $this->assertContains(10, $topicIds);

        $post = $this->posts->findById(100);
        $this->assertSame('[deleted]', $post['body']);

        $revoked = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM user_sessions WHERE user_id = 2 AND revoked_at IS NOT NULL"
        )->fetchColumn();
        $this->assertSame(1, $revoked);
    }
}
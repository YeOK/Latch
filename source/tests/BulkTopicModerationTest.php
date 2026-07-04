<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Models\PostRepository;
use Latch\Models\TopicRepository;
use PHPUnit\Framework\TestCase;

final class BulkTopicModerationTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private TopicRepository $topics;
    private PostRepository $posts;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-bulk-mod-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $this->db->pdo()->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "member",
                created_at TEXT NOT NULL
             );
             CREATE TABLE boards (
                id INTEGER PRIMARY KEY,
                slug TEXT NOT NULL,
                name TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT "",
                sort_order INTEGER NOT NULL DEFAULT 0,
                requires_login_to_read INTEGER NOT NULL DEFAULT 0,
                staff_only_topics INTEGER NOT NULL DEFAULT 0,
                icon_key TEXT NOT NULL DEFAULT "",
                acl_read TEXT NOT NULL DEFAULT "guest",
                acl_topic TEXT NOT NULL DEFAULT "member",
                acl_reply TEXT NOT NULL DEFAULT "member"
             );
             CREATE TABLE topics (
                id INTEGER PRIMARY KEY,
                board_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                slug TEXT NOT NULL,
                is_locked INTEGER NOT NULL DEFAULT 0,
                is_pinned INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                last_post_at TEXT NOT NULL,
                deleted_at TEXT,
                UNIQUE (board_id, slug)
             );
             CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                topic_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                body TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT,
                deleted_at TEXT,
                approval_status TEXT NOT NULL DEFAULT "approved",
                trashed_at TEXT,
                trashed_by_user_id INTEGER,
                trash_restore_topic_id INTEGER,
                trash_restore_board_id INTEGER
             );
             INSERT INTO users (id, username, email, password_hash, created_at) VALUES
                (1, "alice", "alice@test", "hash", "2026-06-30T10:00:00+00:00");
             INSERT INTO boards (id, slug, name) VALUES
                (1, "general", "General");'
        );

        $this->posts = new PostRepository($this->db);
        $this->topics = new TopicRepository($this->db, $this->posts);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testBulkPinAndLockMultipleTopics(): void
    {
        $first = $this->topics->create(1, 1, 'First', 'OP one');
        $second = $this->topics->create(1, 1, 'Second', 'OP two');
        $third = $this->topics->create(1, 1, 'Third', 'OP three');

        foreach ([$first, $second, $third] as $topic) {
            $this->topics->setPinned((int) $topic['id'], true);
            $this->topics->setLocked((int) $topic['id'], true);
        }

        $this->topics->setPinned((int) $third['id'], false);
        $this->topics->setLocked((int) $third['id'], false);

        $afterFirst = $this->topics->findById((int) $first['id']);
        $afterSecond = $this->topics->findById((int) $second['id']);
        $afterThird = $this->topics->findById((int) $third['id']);

        $this->assertSame(1, (int) ($afterFirst['is_pinned'] ?? 0));
        $this->assertSame(1, (int) ($afterFirst['is_locked'] ?? 0));
        $this->assertSame(1, (int) ($afterSecond['is_pinned'] ?? 0));
        $this->assertSame(1, (int) ($afterSecond['is_locked'] ?? 0));
        $this->assertSame(0, (int) ($afterThird['is_pinned'] ?? 0));
        $this->assertSame(0, (int) ($afterThird['is_locked'] ?? 0));
    }

    public function testBulkSoftDeleteTopics(): void
    {
        $first = $this->topics->create(1, 1, 'Delete me', 'OP');
        $second = $this->topics->create(1, 1, 'Keep me', 'OP');

        $this->topics->softDelete((int) $first['id']);

        $deleted = $this->topics->findById((int) $first['id']);
        $kept = $this->topics->findById((int) $second['id']);

        $this->assertNotEmpty($deleted['deleted_at'] ?? null);
        $this->assertNull($kept['deleted_at'] ?? null);
    }
}
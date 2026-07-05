<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


use Latch\Core\Database;
use Latch\Models\BoardRepository;
use Latch\Models\PostRepository;
use Latch\Models\TopicRepository;
use Latch\Models\UserRepository;
use PHPUnit\Framework\TestCase;

final class ModerationTrashTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private PostRepository $posts;
    private TopicRepository $topics;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-trash-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $this->db->pdo()->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY, username TEXT, email TEXT, password_hash TEXT,
                role TEXT, created_at TEXT
             );
             CREATE TABLE boards (
                id INTEGER PRIMARY KEY, slug TEXT, name TEXT, description TEXT DEFAULT "",
                sort_order INTEGER DEFAULT 0, requires_login_to_read INTEGER DEFAULT 0,
                staff_only_topics INTEGER DEFAULT 0,
                acl_read TEXT DEFAULT "guest", acl_topic TEXT DEFAULT "member", acl_reply TEXT DEFAULT "member"
             );
             CREATE TABLE topics (
                id INTEGER PRIMARY KEY, board_id INTEGER, user_id INTEGER, title TEXT, slug TEXT,
                is_locked INTEGER DEFAULT 0, is_pinned INTEGER DEFAULT 0,
                created_at TEXT, last_post_at TEXT, deleted_at TEXT
             );
             CREATE TABLE posts (
                id INTEGER PRIMARY KEY, topic_id INTEGER, user_id INTEGER, body TEXT,
                created_at TEXT, updated_at TEXT, deleted_at TEXT,
                quarantined_at TEXT, quarantined_by_report_id INTEGER,
                approval_status TEXT DEFAULT "approved",
                trashed_at TEXT, trashed_by_user_id INTEGER,
                trash_restore_topic_id INTEGER, trash_restore_board_id INTEGER
             );
             CREATE TABLE reports (
                id INTEGER PRIMARY KEY,
                reporter_id INTEGER NOT NULL,
                target_type TEXT NOT NULL,
                target_id INTEGER NOT NULL,
                reason TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "open",
                created_at TEXT NOT NULL,
                severity TEXT NOT NULL DEFAULT "medium",
                reason_code TEXT NOT NULL DEFAULT "other",
                reason_detail TEXT NOT NULL DEFAULT ""
             );'
        );

        $this->db->pdo()->exec(
            "INSERT INTO users (id, username, email, password_hash, role, created_at) VALUES
             (1, 'admin', 'a@t.test', 'x', 'admin', '2026-01-01T00:00:00+00:00'),
             (2, 'mod', 'm@t.test', 'x', 'mod', '2026-01-01T00:00:00+00:00');
             INSERT INTO boards (id, slug, name) VALUES (1, 'general', 'General');
             INSERT INTO topics (id, board_id, user_id, title, slug, created_at, last_post_at) VALUES
             (10, 1, 1, 'Thread', 'thread', '2026-01-01T00:00:00+00:00', '2026-01-01T00:00:00+00:00');
             INSERT INTO posts (id, topic_id, user_id, body, created_at) VALUES
             (100, 10, 1, 'Hello', '2026-01-01T00:00:00+00:00'),
             (101, 10, 1, 'Reply', '2026-01-02T00:00:00+00:00');"
        );

        $this->posts = new PostRepository($this->db);
        $this->topics = new TopicRepository($this->db, $this->posts);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testTrashHidesPostFromTopicList(): void
    {
        $this->assertTrue($this->posts->trash(101, 2, 10, 1));

        $visible = $this->posts->listByTopic(10, false, null, true);
        $this->assertCount(1, $visible);
        $this->assertSame(100, (int) $visible[0]['id']);
        $this->assertSame(1, $this->posts->countTrashed());
    }

    public function testRestoreReturnsPostToTopic(): void
    {
        $this->posts->trash(101, 2, 10, 1);
        $this->assertTrue($this->posts->restoreFromTrash(101));

        $visible = $this->posts->listByTopic(10, false, null, true);
        $this->assertCount(2, $visible);
        $this->assertSame(0, $this->posts->countTrashed());
    }

    public function testPurgeMarksPostDeleted(): void
    {
        $this->posts->trash(101, 2, 10, 1);
        $this->assertTrue($this->posts->purgeFromTrash(101));

        $post = $this->posts->findById(101);
        $this->assertNotNull($post['deleted_at']);
        $this->assertNull($post['trashed_at']);
        $this->assertFalse($this->posts->isTrashed(101));
    }

    public function testStaffQuarantineSetsTimestamp(): void
    {
        $this->assertTrue($this->posts->staffQuarantine(100));
        $post = $this->posts->findById(100);
        $this->assertNotNull($post['quarantined_at']);
    }

    public function testStaffLiftQuarantineClearsTimestamp(): void
    {
        $this->posts->staffQuarantine(100);
        $this->assertTrue($this->posts->staffLiftQuarantine(100));

        $post = $this->posts->findById(100);
        $this->assertNull($post['quarantined_at']);
        $this->assertSame(0, $this->posts->countQuarantined());
    }

    public function testListQuarantinedIncludesPost(): void
    {
        $this->posts->staffQuarantine(101);

        $entries = $this->posts->listQuarantined(10);
        $this->assertCount(1, $entries);
        $this->assertSame(101, (int) $entries[0]['id']);
        $this->assertSame('Thread', $entries[0]['topic_title']);
    }
}
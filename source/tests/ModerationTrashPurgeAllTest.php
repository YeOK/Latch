<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\ModerationTrashService;
use Latch\Core\Database;
use Latch\Models\BoardRepository;
use Latch\Models\PostRepository;
use Latch\Models\SettingRepository;
use Latch\Models\TopicRepository;
use PHPUnit\Framework\TestCase;

final class ModerationTrashPurgeAllTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private ModerationTrashService $trash;
    private PostRepository $posts;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-trash-purge-all-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $this->db->pdo()->exec(
            'CREATE TABLE settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
             );
             CREATE TABLE users (
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
             );'
        );

        $this->db->pdo()->exec(
            "INSERT INTO users (id, username, email, password_hash, role, created_at) VALUES
             (1, 'admin', 'a@t.test', 'x', 'admin', '2026-01-01T00:00:00+00:00');
             INSERT INTO boards (id, slug, name) VALUES
             (1, 'general', 'General'),
             (2, 'mod-trash', 'Moderation trash');
             INSERT INTO settings (key, value) VALUES ('moderation_trash_board_id', '2');
             INSERT INTO topics (id, board_id, user_id, title, slug, created_at, last_post_at) VALUES
             (10, 1, 1, 'Thread', 'thread', '2026-01-01T00:00:00+00:00', '2026-01-01T00:00:00+00:00'),
             (20, 2, 1, 'Removed from General / Thread', 'removed-1', '2026-01-02T00:00:00+00:00', '2026-01-02T00:00:00+00:00');
             INSERT INTO posts (id, topic_id, user_id, body, created_at, trashed_at, trashed_by_user_id, trash_restore_topic_id, trash_restore_board_id) VALUES
             (101, 20, 1, 'Reply', '2026-01-02T00:00:00+00:00', '2026-01-03T00:00:00+00:00', 1, 10, 1);"
        );

        $this->posts = new PostRepository($this->db);
        $topics = new TopicRepository($this->db, $this->posts);
        $boards = new BoardRepository($this->db);
        $settings = new SettingRepository($this->db);
        $this->trash = new ModerationTrashService($this->db, $boards, $topics, $this->posts, $settings);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testPurgeAllTrashDeletesEveryQueuedPost(): void
    {
        $this->assertSame(1, $this->posts->countTrashed());

        $result = $this->trash->purgeAllTrash();

        $this->assertSame(1, $result['purged_posts']);
        $this->assertSame(1, $result['purged_topics']);
        $this->assertSame(0, $this->posts->countTrashed());

        $post = $this->posts->findById(101);
        $this->assertNotNull($post['deleted_at'] ?? null);
        $this->assertNull($post['trashed_at'] ?? null);
    }

    public function testPurgeAllTrashOnEmptyQueueReturnsZero(): void
    {
        $this->trash->purgeAllTrash();
        $result = $this->trash->purgeAllTrash();

        $this->assertSame(0, $result['purged_posts']);
        $this->assertSame(0, $result['purged_topics']);
    }

    public function testPurgeArchivePostDeletesOrphanedModTrashRow(): void
    {
        $this->db->pdo()->exec(
            "INSERT INTO topics (id, board_id, user_id, title, slug, created_at, last_post_at) VALUES
             (30, 2, 1, 'test', 'test', '2026-01-04T00:00:00+00:00', '2026-01-04T00:00:00+00:00');
             INSERT INTO posts (id, topic_id, user_id, body, created_at) VALUES
             (102, 30, 1, 'Orphan', '2026-01-04T00:00:00+00:00');"
        );

        $queueBefore = $this->trash->countArchiveQueue();
        $this->assertGreaterThan(0, $queueBefore);
        $this->assertFalse($this->posts->isTrashed(102));

        $result = $this->trash->purgeArchivePost(102);
        $this->assertNotNull($result);
        $this->assertSame(30, $result['archive_topic_id']);
        $this->assertSame(0, $result['restore_topic_id']);

        $post = $this->posts->findById(102);
        $this->assertNotNull($post['deleted_at'] ?? null);
        $this->assertSame($queueBefore - 1, $this->trash->countArchiveQueue());
    }

    public function testPurgeTrashTopicDeletesOrphanedArchiveTopic(): void
    {
        $this->db->pdo()->exec(
            "INSERT INTO topics (id, board_id, user_id, title, slug, created_at, last_post_at) VALUES
             (31, 2, 1, 'test', 'test-orphan', '2026-01-05T00:00:00+00:00', '2026-01-05T00:00:00+00:00');
             INSERT INTO posts (id, topic_id, user_id, body, created_at) VALUES
             (103, 31, 1, 'Orphan', '2026-01-05T00:00:00+00:00');"
        );

        $result = $this->trash->purgeTrashTopic(31);
        $this->assertNotNull($result);
        $this->assertCount(1, $result['purged']);

        $topic = $this->db->pdo()->query('SELECT deleted_at FROM topics WHERE id = 31')->fetch();
        $this->assertNotNull($topic['deleted_at'] ?? null);
    }
}
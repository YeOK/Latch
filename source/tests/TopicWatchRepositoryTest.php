<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Models\TopicWatchRepository;
use PHPUnit\Framework\TestCase;

final class TopicWatchRepositoryTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private TopicWatchRepository $watches;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-watch-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT);
             CREATE TABLE boards (id INTEGER PRIMARY KEY, slug TEXT, name TEXT);
             CREATE TABLE topics (
                id INTEGER PRIMARY KEY,
                board_id INTEGER,
                user_id INTEGER,
                title TEXT,
                slug TEXT,
                deleted_at TEXT,
                last_post_at TEXT NOT NULL
             );
             CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                topic_id INTEGER,
                body TEXT,
                deleted_at TEXT,
                approval_status TEXT DEFAULT "approved",
                created_at TEXT NOT NULL
             );
             CREATE TABLE topic_watches (
                user_id INTEGER NOT NULL,
                topic_id INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                PRIMARY KEY (user_id, topic_id)
             );
             CREATE TABLE topic_reads (
                user_id INTEGER NOT NULL,
                topic_id INTEGER NOT NULL,
                last_read_post_id INTEGER,
                last_read_at TEXT NOT NULL,
                PRIMARY KEY (user_id, topic_id)
             );'
        );
        $pdo->exec(
            "INSERT INTO users (id, username) VALUES (1, 'alice'), (2, 'bob');
             INSERT INTO boards (id, slug, name) VALUES (1, 'general', 'General');
             INSERT INTO topics (id, board_id, user_id, title, slug, last_post_at) VALUES
                (10, 1, 1, 'Thread', 'thread', '2026-06-30T10:00:00+00:00');
             INSERT INTO posts (id, topic_id, body, created_at) VALUES
                (100, 10, 'Hello', '2026-06-30T10:00:00+00:00');"
        );

        $this->watches = new TopicWatchRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testToggleWatch(): void
    {
        $this->assertFalse($this->watches->isWatching(2, 10));
        $this->assertTrue($this->watches->toggleWatch(2, 10));
        $this->assertTrue($this->watches->isWatching(2, 10));
        $this->assertFalse($this->watches->toggleWatch(2, 10));
    }

    public function testUnreadAfterNewActivity(): void
    {
        $this->watches->watch(2, 10);
        $this->watches->markRead(2, 10, 100, '2026-06-30T10:00:00+00:00');

        $flags = $this->watches->unreadFlagsForTopics(2, [10]);
        $this->assertFalse($flags[10]);

        $this->db->pdo()->exec(
            "INSERT INTO posts (id, topic_id, body, created_at) VALUES (101, 10, 'Reply', '2026-06-30T12:00:00+00:00');"
        );

        $flags = $this->watches->unreadFlagsForTopics(2, [10]);
        $this->assertTrue($flags[10]);
    }

    public function testUnreadBoardCount(): void
    {
        $this->watches->markRead(2, 10, 100, '2026-06-30T10:00:00+00:00');

        $this->db->pdo()->exec(
            "UPDATE topics SET last_post_at = '2026-06-30T12:00:00+00:00';
             INSERT INTO posts (id, topic_id, body, created_at) VALUES (101, 10, 'Reply', '2026-06-30T12:00:00+00:00');"
        );

        $counts = $this->watches->unreadCountsForBoards(2, [1]);
        $this->assertSame(1, $counts[1]);
    }

    public function testUnreadIgnoresStaleTopicLastPostAt(): void
    {
        $this->watches->markRead(2, 10, 100, '2026-06-30T10:00:00+00:00');

        $this->db->pdo()->exec(
            "UPDATE topics SET last_post_at = '2026-06-30T12:00:00+00:00';
             INSERT INTO posts (id, topic_id, body, created_at, deleted_at) VALUES
                (101, 10, 'Removed', '2026-06-30T12:00:00+00:00', '2026-06-30T12:05:00+00:00');"
        );

        $flags = $this->watches->unreadFlagsForTopics(2, [10]);
        $this->assertFalse($flags[10]);

        $counts = $this->watches->unreadCountsForBoards(2, [1]);
        $this->assertSame(0, $counts[1]);
    }

    public function testNeverReadTopicIsUnread(): void
    {
        $flags = $this->watches->unreadFlagsForTopics(2, [10]);
        $this->assertTrue($flags[10]);

        $counts = $this->watches->unreadCountsForBoards(2, [1]);
        $this->assertSame(1, $counts[1]);
    }
}
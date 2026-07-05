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
use Latch\Models\TopicRepository;
use Latch\Support\TopicListSort;
use PHPUnit\Framework\TestCase;

final class TopicRepositoryHomeTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private TopicRepository $topics;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-topic-home-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, avatar_url TEXT, email TEXT);
             CREATE TABLE boards (id INTEGER PRIMARY KEY, slug TEXT, name TEXT);
             CREATE TABLE topics (
                id INTEGER PRIMARY KEY,
                board_id INTEGER,
                user_id INTEGER,
                title TEXT,
                slug TEXT,
                is_pinned INTEGER NOT NULL DEFAULT 0,
                deleted_at TEXT,
                created_at TEXT NOT NULL,
                last_post_at TEXT NOT NULL
             );
             CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                topic_id INTEGER,
                user_id INTEGER,
                body TEXT,
                deleted_at TEXT,
                approval_status TEXT DEFAULT "approved",
                created_at TEXT NOT NULL
             );
             INSERT INTO users (id, username) VALUES (1, "alice");
             INSERT INTO boards (id, slug, name) VALUES (1, "news", "News"), (2, "general", "General");
             INSERT INTO topics (id, board_id, user_id, title, slug, created_at, last_post_at) VALUES
                (10, 1, 1, "Older", "older", "2026-06-28T10:00:00+00:00", "2026-06-28T10:00:00+00:00"),
                (11, 1, 1, "Newer", "newer", "2026-06-30T12:00:00+00:00", "2026-06-30T12:00:00+00:00"),
                (20, 2, 1, "Only", "only", "2026-06-29T08:00:00+00:00", "2026-06-29T08:00:00+00:00");
             INSERT INTO posts (id, topic_id, user_id, body, created_at) VALUES
                (100, 10, 1, "a", "2026-06-28T10:00:00+00:00"),
                (101, 10, 1, "b", "2026-06-28T11:00:00+00:00"),
                (110, 11, 1, "c", "2026-06-30T12:00:00+00:00"),
                (200, 20, 1, "d", "2026-06-29T08:00:00+00:00");'
        );

        $this->topics = new TopicRepository($this->db, new PostRepository($this->db));
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testActivitySummariesForBoards(): void
    {
        $summaries = $this->topics->activitySummariesForBoards([1, 2]);

        $this->assertSame(2, $summaries[1]['topic_count']);
        $this->assertSame(3, $summaries[1]['post_count']);
        $this->assertSame('2026-06-30T12:00:00+00:00', $summaries[1]['last_activity_at']);
        $this->assertSame(1, $summaries[2]['topic_count']);
        $this->assertSame(1, $summaries[2]['post_count']);
    }

    public function testRecentTopicsForBoardsLimitsPerBoard(): void
    {
        $recent = $this->topics->recentTopicsForBoards([1], 1);

        $this->assertCount(1, $recent[1]);
        $this->assertSame(11, (int) $recent[1][0]['id']);
        $this->assertSame('Newer', $recent[1][0]['title']);
    }

    public function testRecentTopicsForBoardsBatchesMultipleBoards(): void
    {
        $recent = $this->topics->recentTopicsForBoards([1, 2], 2);

        $this->assertCount(2, $recent[1]);
        $this->assertCount(1, $recent[2]);
        $this->assertSame([11, 10], array_map(static fn (array $t): int => (int) $t['id'], $recent[1]));
        $this->assertSame(20, (int) $recent[2][0]['id']);
    }

    public function testListByBoardSortsByLatestActivity(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec(
            'UPDATE topics SET last_post_at = "2026-06-28T11:00:00+00:00" WHERE id = 10;
             UPDATE topics SET last_post_at = "2026-06-28T09:00:00+00:00" WHERE id = 11;'
        );

        $topics = $this->topics->listByBoard(1, 1, 10, null, false, TopicListSort::ACTIVITY);

        $this->assertSame([10, 11], array_map(static fn (array $t): int => (int) $t['id'], $topics));
    }
}
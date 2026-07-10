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
use PHPUnit\Framework\TestCase;

final class PostPaginationTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private PostRepository $posts;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-post-page-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                email TEXT,
                display_name TEXT,
                deleted_at TEXT
             );
             CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                topic_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                body TEXT NOT NULL,
                created_at TEXT NOT NULL,
                deleted_at TEXT,
                trashed_at TEXT,
                approval_status TEXT NOT NULL DEFAULT "approved",
                quarantined_at TEXT
             );
             INSERT INTO users (id, username, email) VALUES (1, "alice", "a@example.com");
             INSERT INTO posts (id, topic_id, user_id, body, created_at, approval_status) VALUES
                (1, 10, 1, "one", "2026-01-01T00:00:00Z", "approved"),
                (2, 10, 1, "two", "2026-01-02T00:00:00Z", "approved"),
                (3, 10, 1, "three", "2026-01-03T00:00:00Z", "approved"),
                (4, 10, 1, "four", "2026-01-04T00:00:00Z", "approved"),
                (5, 10, 1, "five", "2026-01-05T00:00:00Z", "approved");'
        );
        $this->posts = new PostRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testCountVisibleByTopic(): void
    {
        $this->assertSame(5, $this->posts->countVisibleByTopic(10));
    }

    public function testListByTopicCursorFirstPage(): void
    {
        $page = $this->posts->listByTopicCursor(10, null, false, false, 2, null);

        $this->assertCount(2, $page);
        $this->assertSame(1, (int) $page[0]['id']);
        $this->assertSame(2, (int) $page[1]['id']);
    }

    public function testListByTopicCursorAfterId(): void
    {
        $page = $this->posts->listByTopicCursor(10, null, false, false, 2, 2);

        $this->assertCount(2, $page);
        $this->assertSame(3, (int) $page[0]['id']);
        $this->assertSame(4, (int) $page[1]['id']);
    }

    public function testListByTopicTail(): void
    {
        $tail = $this->posts->listByTopicTail(10, null, false, false, 2);

        $this->assertCount(2, $tail);
        $this->assertSame(4, (int) $tail[0]['id']);
        $this->assertSame(5, (int) $tail[1]['id']);
    }

    public function testHasPostsAfterAndBefore(): void
    {
        $this->assertTrue($this->posts->hasPostsAfter(10, 2, null, false, false));
        $this->assertFalse($this->posts->hasPostsAfter(10, 5, null, false, false));
        $this->assertTrue($this->posts->hasPostsBefore(10, 4, null, false, false));
        $this->assertFalse($this->posts->hasPostsBefore(10, 1, null, false, false));
    }

    public function testCountVisibleUpToId(): void
    {
        $this->assertSame(3, $this->posts->countVisibleUpToId(10, 3, null, false, false));
    }
}
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

/**
 * Regression: topicVisibilitySql must not bind unused PDO params (member topic 500).
 */
final class PostRepositoryVisibilityTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private PostRepository $posts;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-post-vis-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $this->db->pdo()->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                email TEXT
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
             INSERT INTO users (id, username, email) VALUES
                (1, "alice", "a@example.com"),
                (2, "bob", "b@example.com");
             INSERT INTO posts (id, topic_id, user_id, body, created_at, approval_status) VALUES
                (1, 10, 1, "approved", "2026-01-01T00:00:00Z", "approved"),
                (2, 10, 2, "pending bob", "2026-01-02T00:00:00Z", "pending"),
                (3, 10, 1, "approved two", "2026-01-03T00:00:00Z", "approved");',
        );
        $this->posts = new PostRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testCountVisibleByTopicForLoggedInMemberDoesNotThrow(): void
    {
        $this->assertSame(2, $this->posts->countVisibleByTopic(10, 1, false));
        $this->assertSame(3, $this->posts->countVisibleByTopic(10, 2, false));
    }

    public function testMemberSeesOwnPendingPostInCount(): void
    {
        $this->assertSame(3, $this->posts->countVisibleByTopic(10, 2, false));
    }

    public function testMemberDoesNotSeeOthersPendingPostInCount(): void
    {
        $this->assertSame(2, $this->posts->countVisibleByTopic(10, 1, false));
    }

    public function testModSeesAllPostsRegardlessOfApproval(): void
    {
        $this->assertSame(3, $this->posts->countVisibleByTopic(10, 1, true));
    }

    public function testCountVisibleByTopicForGuestDoesNotThrow(): void
    {
        $this->assertSame(2, $this->posts->countVisibleByTopic(10, null, false));
    }
}
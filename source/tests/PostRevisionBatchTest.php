<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Models\PostRevisionRepository;
use PHPUnit\Framework\TestCase;

final class PostRevisionBatchTest extends TestCase
{
    private string $dbPath;
    private PostRevisionRepository $revisions;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-rev-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database($this->dbPath);
        $db->pdo()->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                deleted_at TEXT
             );
             CREATE TABLE post_revisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                editor_id INTEGER NOT NULL,
                body TEXT NOT NULL,
                created_at TEXT NOT NULL
             );
             INSERT INTO users (id, username) VALUES (1, "ed");
             INSERT INTO post_revisions (post_id, editor_id, body, created_at) VALUES
                (10, 1, "a", "2026-01-01T00:00:00Z"),
                (10, 1, "b", "2026-01-02T00:00:00Z"),
                (11, 1, "c", "2026-01-03T00:00:00Z");'
        );
        $this->revisions = new PostRevisionRepository($db);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testCountsForPostsBatch(): void
    {
        $counts = $this->revisions->countsForPosts([10, 11, 12]);
        $this->assertSame(2, $counts[10]);
        $this->assertSame(1, $counts[11]);
        $this->assertArrayNotHasKey(12, $counts);
        $this->assertSame(2, $this->revisions->countForPost(10));
    }
}

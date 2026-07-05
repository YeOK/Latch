<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Cache;
use Latch\Core\Database;
use Latch\Models\PostRepository;
use Latch\Models\TopicRepository;
use PHPUnit\Framework\TestCase;

final class GuestCacheBustTest extends TestCase
{
    private string $dbPath;
    private Database $db;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-cache-bust-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT);
             CREATE TABLE boards (id INTEGER PRIMARY KEY);
             CREATE TABLE topics (id INTEGER PRIMARY KEY, board_id INTEGER, deleted_at TEXT);
             CREATE TABLE posts (id INTEGER PRIMARY KEY, topic_id INTEGER, user_id INTEGER, deleted_at TEXT);
             INSERT INTO users (id, username) VALUES (1, "alice"), (2, "bob");
             INSERT INTO boards (id) VALUES (10);
             INSERT INTO topics (id, board_id, deleted_at) VALUES (100, 10, NULL), (101, 10, NULL), (200, 20, NULL);
             INSERT INTO posts (id, topic_id, user_id, deleted_at) VALUES
                (1, 100, 1, NULL), (2, 100, 2, NULL), (3, 101, 1, NULL);'
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testActiveIdsByBoard(): void
    {
        $topics = new TopicRepository($this->db, new PostRepository($this->db));

        $this->assertSame([100, 101], $topics->activeIdsByBoard(10));
        $this->assertSame([], $topics->activeIdsByBoard(99));
    }

    public function testDistinctAuthorIdsForBoard(): void
    {
        $posts = new PostRepository($this->db);

        $this->assertEqualsCanonicalizing([1, 2], $posts->distinctAuthorIdsForBoard(10));
        $this->assertSame([], $posts->distinctAuthorIdsForBoard(99));
    }

    public function testCacheInvalidateTagRemovesTaggedPages(): void
    {
        $cacheDir = sys_get_temp_dir() . '/latch-cache-' . bin2hex(random_bytes(4));
        $cache = new Cache($cacheDir);
        $key = Cache::makeKey('/board/news', ['page' => 1]);

        $cache->set($key, '<html>news</html>', 300, [Cache::tagBoard(10), Cache::tagSite()]);
        $this->assertNotNull($cache->get($key));

        $cache->invalidateTag(Cache::tagBoard(10));
        $this->assertNull($cache->get($key));
    }
}
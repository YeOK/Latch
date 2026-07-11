<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Core\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private Database $db;
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/latch-rate-limit-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database('sqlite:' . $path);
        $this->db->pdo()->exec(
            'CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                topic_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                body TEXT NOT NULL,
                created_at TEXT NOT NULL,
                deleted_at TEXT
            )',
        );
        $this->limiter = new RateLimiter($this->db);
    }

    public function testStaffBypassesPostLimit(): void
    {
        $this->seedPosts(1, 12);

        $this->assertFalse($this->limiter->exceedsPostLimit(['id' => 1, 'role' => 'admin'], 10, 10));
        $this->assertFalse($this->limiter->exceedsPostLimit(['id' => 1, 'role' => 'mod'], 10, 10));
        $this->assertTrue($this->limiter->exceedsPostLimit(['id' => 1, 'role' => 'member'], 10, 10));
    }

    private function seedPosts(int $userId, int $count): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO posts (topic_id, user_id, body, created_at) VALUES (1, :user_id, :body, :created_at)',
        );

        for ($i = 0; $i < $count; ++$i) {
            $stmt->execute([
                'user_id' => $userId,
                'body' => 'post ' . $i,
                'created_at' => gmdate('c'),
            ]);
        }
    }
}
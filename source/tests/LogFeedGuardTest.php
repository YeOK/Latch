<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Config;
use Latch\Core\RateLimiter;
use Latch\Core\Request;
use Latch\Core\Session;
use Latch\Support\Logs\LogFeedGuard;
use PHPUnit\Framework\TestCase;

final class LogFeedGuardTest extends TestCase
{
    private RateLimiter $rateLimiter;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $db = new \Latch\Core\Database(':memory:');
        $db->pdo()->exec(
            'CREATE TABLE api_rate_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bucket_key TEXT NOT NULL,
                requested_at TEXT NOT NULL
            );'
        );
        $this->rateLimiter = new RateLimiter($db);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testFeedRateLimitBlocksAfterThirtyRequests(): void
    {
        $config = new Config(LATCH_ROOT . '/config');
        $session = new Session();
        $session->start($config);
        $guard = new LogFeedGuard($this->rateLimiter, $session);

        for ($i = 0; $i < 30; $i++) {
            $this->assertFalse($guard->tooManyFeedRequests(42));
            $guard->recordFeedRequest(42);
        }

        $this->assertTrue($guard->tooManyFeedRequests(42));
    }

    public function testFeedAuditDebounceWithinFiveMinutes(): void
    {
        $config = new Config(LATCH_ROOT . '/config');
        $session = new Session();
        $session->start($config);
        $guard = new LogFeedGuard($this->rateLimiter, $session);

        $this->assertTrue($guard->shouldRecordFeedAudit('latch.security'));
        $this->assertFalse($guard->shouldRecordFeedAudit('latch.security'));
    }
}
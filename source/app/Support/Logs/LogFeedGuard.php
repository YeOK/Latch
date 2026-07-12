<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support\Logs;

use Latch\Core\RateLimiter;
use Latch\Core\Session;

final class LogFeedGuard
{
    private const FEED_LIMIT_PER_MINUTE = 30;
    private const AUDIT_DEBOUNCE_SECONDS = 300;

    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly Session $session,
    ) {
    }

    public function tooManyFeedRequests(int $userId): bool
    {
        return $this->rateLimiter->tooManyApiRequests(
            'logs_feed:' . $userId,
            self::FEED_LIMIT_PER_MINUTE,
            1,
        );
    }

    public function recordFeedRequest(int $userId): void
    {
        $this->rateLimiter->recordApiRequest('logs_feed:' . $userId);
    }

    public function shouldRecordFeedAudit(string $source): bool
    {
        $key = 'logs_feed_audit:' . $source;
        $last = $this->session->get($key);
        if (is_int($last) && (time() - $last) < self::AUDIT_DEBOUNCE_SECONDS) {
            return false;
        }

        $this->session->set($key, time());

        return true;
    }
}
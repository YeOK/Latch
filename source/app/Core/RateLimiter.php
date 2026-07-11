<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Simple DB-backed rate limiting for login and posting.
 */
final class RateLimiter
{
    public function __construct(private readonly Database $db)
    {
    }

    public function tooManyLoginAttempts(string $ip, int $maxAttempts, int $windowMinutes): bool
    {
        $since = gmdate('c', time() - ($windowMinutes * 60));

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = :ip AND success = 0 AND attempted_at >= :since'
        );
        $stmt->execute(['ip' => $ip, 'since' => $since]);

        return (int) $stmt->fetchColumn() >= $maxAttempts;
    }

    public function recordLoginAttempt(string $ip, ?string $username, bool $success): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO login_attempts (ip_address, username, attempted_at, success)
             VALUES (:ip, :username, :attempted_at, :success)'
        );
        $stmt->execute([
            'ip' => $ip,
            'username' => $username,
            'attempted_at' => gmdate('c'),
            'success' => $success ? 1 : 0,
        ]);
    }

    public function tooManyPosts(int $userId, int $maxPosts, int $windowMinutes): bool
    {
        $since = gmdate('c', time() - ($windowMinutes * 60));

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM posts
             WHERE user_id = :user_id AND created_at >= :since AND deleted_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId, 'since' => $since]);

        return (int) $stmt->fetchColumn() >= $maxPosts;
    }

    /**
     * Post flood limit for members; staff (admin/mod) are exempt for operator workflows (e.g. md-import).
     *
     * @param array<string, mixed> $user
     */
    public function exceedsPostLimit(array $user, int $maxPosts, int $windowMinutes): bool
    {
        $role = (string) ($user['role'] ?? '');
        if (in_array($role, ['admin', 'mod'], true)) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        return $this->tooManyPosts($userId, $maxPosts, $windowMinutes);
    }

    public function tooManySearches(string $ip, int $maxAttempts = 30, int $windowMinutes = 1): bool
    {
        if (!$this->searchAttemptsTableExists()) {
            return false;
        }

        $since = gmdate('c', time() - ($windowMinutes * 60));

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM search_attempts
             WHERE ip_address = :ip AND searched_at >= :since'
        );
        $stmt->execute(['ip' => $ip, 'since' => $since]);

        return (int) $stmt->fetchColumn() >= $maxAttempts;
    }

    public function recordSearchAttempt(string $ip): void
    {
        if (!$this->searchAttemptsTableExists()) {
            return;
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO search_attempts (ip_address, searched_at) VALUES (:ip, :searched_at)'
        );
        $stmt->execute([
            'ip' => $ip,
            'searched_at' => gmdate('c'),
        ]);
    }

    public function pruneSearchAttempts(int $olderThanMinutes = 120): int
    {
        if (!$this->searchAttemptsTableExists()) {
            return 0;
        }

        $before = gmdate('c', time() - ($olderThanMinutes * 60));
        $stmt = $this->db->pdo()->prepare('DELETE FROM search_attempts WHERE searched_at < :before');
        $stmt->execute(['before' => $before]);

        return $stmt->rowCount();
    }

    public function tooManyRegistrations(string $ip, int $maxAttempts, int $windowMinutes): bool
    {
        if (!$this->registrationAttemptsTableExists()) {
            return false;
        }

        $since = gmdate('c', time() - ($windowMinutes * 60));

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM registration_attempts
             WHERE ip_address = :ip AND attempted_at >= :since'
        );
        $stmt->execute(['ip' => $ip, 'since' => $since]);

        return (int) $stmt->fetchColumn() >= $maxAttempts;
    }

    public function recordRegistrationAttempt(string $ip, bool $success): void
    {
        if (!$this->registrationAttemptsTableExists()) {
            return;
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO registration_attempts (ip_address, attempted_at, success)
             VALUES (:ip, :attempted_at, :success)'
        );
        $stmt->execute([
            'ip' => $ip,
            'attempted_at' => gmdate('c'),
            'success' => $success ? 1 : 0,
        ]);
    }

    private function registrationAttemptsTableExists(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'registration_attempts' LIMIT 1"
        );
        $stmt->execute();
        $exists = (bool) $stmt->fetchColumn();

        return $exists;
    }

    private function searchAttemptsTableExists(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'search_attempts' LIMIT 1"
        );
        $stmt->execute();
        $exists = (bool) $stmt->fetchColumn();

        return $exists;
    }

    public function tooManyApiRequests(string $bucketKey, int $maxAttempts, int $windowMinutes): bool
    {
        if (!$this->apiRateAttemptsTableExists()) {
            return false;
        }

        $since = gmdate('c', time() - ($windowMinutes * 60));

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM api_rate_attempts
             WHERE bucket_key = :bucket_key AND requested_at >= :since'
        );
        $stmt->execute(['bucket_key' => $bucketKey, 'since' => $since]);

        return (int) $stmt->fetchColumn() >= $maxAttempts;
    }

    public function recordApiRequest(string $bucketKey): void
    {
        if (!$this->apiRateAttemptsTableExists()) {
            return;
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO api_rate_attempts (bucket_key, requested_at) VALUES (:bucket_key, :requested_at)'
        );
        $stmt->execute([
            'bucket_key' => $bucketKey,
            'requested_at' => gmdate('c'),
        ]);
    }

    public function pruneApiRateAttempts(int $olderThanMinutes = 120): int
    {
        if (!$this->apiRateAttemptsTableExists()) {
            return 0;
        }

        $before = gmdate('c', time() - ($olderThanMinutes * 60));
        $stmt = $this->db->pdo()->prepare('DELETE FROM api_rate_attempts WHERE requested_at < :before');
        $stmt->execute(['before' => $before]);

        return $stmt->rowCount();
    }

    private function apiRateAttemptsTableExists(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'api_rate_attempts' LIMIT 1"
        );
        $stmt->execute();
        $exists = (bool) $stmt->fetchColumn();

        return $exists;
    }
}
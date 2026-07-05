<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Models;

use Latch\Core\Database;

final class EmailChangeRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(int $userId, string $newEmail, string $token, int $ttlHours = 24): void
    {
        $this->invalidateForUser($userId);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO email_change_requests (user_id, new_email, token_hash, expires_at, created_at)
             VALUES (:user_id, :new_email, :token_hash, :expires_at, :created_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'new_email' => strtolower(trim($newEmail)),
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('c', time() + $ttlHours * 3600),
            'created_at' => gmdate('c'),
        ]);
    }

    public function findValid(string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ecr.*, u.username, u.email AS current_email
             FROM email_change_requests ecr
             JOIN users u ON u.id = ecr.user_id
             WHERE ecr.token_hash = :hash
               AND ecr.used_at IS NULL
               AND ecr.expires_at > :now
             LIMIT 1'
        );
        $stmt->execute([
            'hash' => hash('sha256', $token),
            'now' => gmdate('c'),
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function markUsed(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE email_change_requests SET used_at = :used_at WHERE id = :id'
        );
        $stmt->execute(['used_at' => gmdate('c'), 'id' => $id]);
    }

    public function invalidateForUser(int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE email_change_requests SET used_at = :used_at
             WHERE user_id = :user_id AND used_at IS NULL'
        );
        $stmt->execute(['used_at' => gmdate('c'), 'user_id' => $userId]);
    }

    public function pruneExpired(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM email_change_requests WHERE expires_at < :cutoff OR used_at IS NOT NULL'
        );
        $stmt->execute(['cutoff' => gmdate('c', time() - 86400 * 7)]);

        return $stmt->rowCount();
    }
}
<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Models;

use Latch\Core\Database;

final class PasswordResetRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(int $userId, string $token, int $ttlHours = 1): void
    {
        $this->invalidateForUser($userId);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
             VALUES (:user_id, :token_hash, :expires_at, :created_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('c', time() + $ttlHours * 3600),
            'created_at' => gmdate('c'),
        ]);
    }

    public function findValid(string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pr.*, u.username, u.email
             FROM password_resets pr
             JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = :hash
               AND pr.used_at IS NULL
               AND pr.expires_at > :now
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
        $stmt = $this->db->pdo()->prepare('UPDATE password_resets SET used_at = :used_at WHERE id = :id');
        $stmt->execute(['used_at' => gmdate('c'), 'id' => $id]);
    }

    public function invalidateForUser(int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE password_resets SET used_at = :used_at WHERE user_id = :user_id AND used_at IS NULL'
        );
        $stmt->execute(['used_at' => gmdate('c'), 'user_id' => $userId]);
    }

    public function pruneExpired(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM password_resets WHERE expires_at < :cutoff OR used_at IS NOT NULL'
        );
        $stmt->execute(['cutoff' => gmdate('c', time() - 86400 * 7)]);

        return $stmt->rowCount();
    }
}
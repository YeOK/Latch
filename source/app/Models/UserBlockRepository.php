<?php

declare(strict_types=1);

namespace Latch\Models;

use Latch\Core\Database;

final class UserBlockRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function isBlocked(int $blockerId, int $blockedId): bool
    {
        if ($blockerId <= 0 || $blockedId <= 0 || $blockerId === $blockedId) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM user_blocks WHERE blocker_id = :blocker_id AND blocked_id = :blocked_id LIMIT 1'
        );
        $stmt->execute([
            'blocker_id' => $blockerId,
            'blocked_id' => $blockedId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function block(int $blockerId, int $blockedId): void
    {
        if ($blockerId <= 0 || $blockedId <= 0 || $blockerId === $blockedId) {
            return;
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT OR IGNORE INTO user_blocks (blocker_id, blocked_id, created_at)
             VALUES (:blocker_id, :blocked_id, :created_at)'
        );
        $stmt->execute([
            'blocker_id' => $blockerId,
            'blocked_id' => $blockedId,
            'created_at' => gmdate('c'),
        ]);
    }
}
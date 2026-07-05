<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Models;

use Latch\Core\Database;

final class AuditLogRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(
        ?int $actorId,
        string $action,
        string $targetType,
        ?int $targetId,
        string $ip,
        array $metadata = [],
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO audit_log (actor_id, action, target_type, target_id, ip_address, metadata, created_at)
             VALUES (:actor_id, :action, :target_type, :target_id, :ip, :metadata, :created_at)'
        );
        $stmt->execute([
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip' => $ip,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => gmdate('c'),
        ]);
    }

    public function countAll(): int
    {
        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    }

    public function recent(int $limit = 100): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.*, u.username AS actor_username
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.actor_id
             ORDER BY a.created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Models;

use Latch\Core\Database;
use Latch\Core\ReportReasons;

final class ReportRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(
        int $reporterId,
        string $targetType,
        int $targetId,
        string $reasonCode,
        string $severity,
        string $reasonDetail = '',
        bool $quarantineApplied = false,
    ): int {
        $legacyReason = $reasonDetail !== '' ? $reasonDetail : $reasonCode;

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO reports (
                reporter_id, target_type, target_id, reason, reason_code, reason_detail,
                severity, status, quarantine_applied, created_at
             ) VALUES (
                :reporter_id, :target_type, :target_id, :reason, :reason_code, :reason_detail,
                :severity, :status, :quarantine_applied, :created_at
             )'
        );
        $stmt->execute([
            'reporter_id' => $reporterId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $legacyReason,
            'reason_code' => $reasonCode,
            'reason_detail' => $reasonDetail,
            'severity' => $severity,
            'status' => 'open',
            'quarantine_applied' => $quarantineApplied ? 1 : 0,
            'created_at' => gmdate('c'),
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM reports WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function hasOpenReportByReporter(int $reporterId, string $targetType, int $targetId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM reports
             WHERE reporter_id = :reporter_id
               AND target_type = :target_type
               AND target_id = :target_id
               AND status = 'open'
             LIMIT 1"
        );
        $stmt->execute([
            'reporter_id' => $reporterId,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function countOpenForPost(int $postId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM reports WHERE target_type = 'post' AND target_id = :id AND status = 'open'"
        );
        $stmt->execute(['id' => $postId]);

        return (int) $stmt->fetchColumn();
    }

    public function countOpenSevereForPost(int $postId, string $minSeverity): int
    {
        $minRank = ReportReasons::severityRank($minSeverity);
        $stmt = $this->db->pdo()->prepare(
            "SELECT severity FROM reports
             WHERE target_type = 'post' AND target_id = :id AND status = 'open'"
        );
        $stmt->execute(['id' => $postId]);
        $count = 0;
        while ($row = $stmt->fetch()) {
            if (ReportReasons::severityRank((string) $row['severity']) >= $minRank) {
                $count++;
            }
        }

        return $count;
    }

    public function countRecentByReporter(int $reporterId, int $minutes = 60): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM reports
             WHERE reporter_id = :reporter_id
               AND created_at > :since'
        );
        $stmt->execute([
            'reporter_id' => $reporterId,
            'since' => gmdate('c', time() - $minutes * 60),
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function openCount(): int
    {
        return (int) $this->db->pdo()->query("SELECT COUNT(*) FROM reports WHERE status = 'open'")->fetchColumn();
    }

    public function openReports(): array
    {
        return $this->db->pdo()->query($this->openReportsSql())->fetchAll();
    }

    public function topOpenReport(): ?array
    {
        $stmt = $this->db->pdo()->query($this->openReportsSql() . ' LIMIT 1');
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function resolveOpenForTarget(string $targetType, int $targetId, int $resolverId, string $status, string $action): int
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE reports SET
                status = :status,
                resolution_action = :action,
                resolved_by = :resolved_by,
                resolved_at = :resolved_at
             WHERE target_type = :target_type
               AND target_id = :target_id
               AND status = 'open'"
        );
        $stmt->execute([
            'status' => $status,
            'action' => $action,
            'resolved_by' => $resolverId,
            'resolved_at' => gmdate('c'),
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);

        return $stmt->rowCount();
    }

    public function markQuarantineApplied(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE reports SET quarantine_applied = 1 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function resolve(int $id, int $resolverId, string $status, string $action): void
    {
        if (!in_array($status, ['resolved', 'dismissed'], true)) {
            return;
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE reports SET
                status = :status,
                resolution_action = :action,
                resolved_by = :resolved_by,
                resolved_at = :resolved_at
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'action' => $action,
            'resolved_by' => $resolverId,
            'resolved_at' => gmdate('c'),
            'id' => $id,
        ]);
    }

    private function openReportsSql(): string
    {
        return "SELECT r.*, u.username AS reporter_username,
                       p.topic_id AS post_topic_id,
                       p.user_id AS post_author_id,
                       pu.username AS post_author_username,
                       tu.username AS target_username
                FROM reports r
                JOIN users u ON u.id = r.reporter_id
                LEFT JOIN posts p ON r.target_type = 'post' AND p.id = r.target_id
                LEFT JOIN users pu ON pu.id = p.user_id
                LEFT JOIN users tu ON r.target_type = 'user' AND tu.id = r.target_id
                WHERE r.status = 'open'
                ORDER BY CASE r.severity
                    WHEN 'critical' THEN 4
                    WHEN 'high' THEN 3
                    WHEN 'medium' THEN 2
                    ELSE 1
                END DESC, r.created_at ASC";
    }
}
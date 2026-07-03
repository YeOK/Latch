<?php

declare(strict_types=1);

namespace Latch\Models;

use Latch\Core\Database;

final class UserWarningRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function issue(int $userId, int $issuedBy, string $reason, ?int $reportId = null): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO user_warnings (user_id, issued_by, report_id, reason, created_at)
             VALUES (:user_id, :issued_by, :report_id, :reason, :created_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'issued_by' => $issuedBy,
            'report_id' => $reportId,
            'reason' => $reason,
            'created_at' => gmdate('c'),
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function countForUser(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM user_warnings WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function listForUser(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT w.*, issuer.username AS issued_by_username
             FROM user_warnings w
             JOIN users issuer ON issuer.id = w.issued_by
             WHERE w.user_id = :user_id
             ORDER BY w.created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', max(1, min(200, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
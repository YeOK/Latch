<?php

declare(strict_types=1);

namespace Latch\Models;

use Latch\Core\Database;

final class ApiAuditLogRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function record(
        ?string $clientId,
        ?int $userId,
        string $method,
        string $path,
        int $statusCode,
        string $ip,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO api_audit_log (client_id, user_id, method, path, status_code, ip_address, created_at)
             VALUES (:client_id, :user_id, :method, :path, :status_code, :ip, :created_at)'
        );
        $stmt->execute([
            'client_id' => $clientId,
            'user_id' => $userId,
            'method' => $method,
            'path' => $path,
            'status_code' => $statusCode,
            'ip' => $ip,
            'created_at' => gmdate('c'),
        ]);
    }

    public function prune(int $olderThanDays = 90): int
    {
        $cutoff = gmdate('c', time() - ($olderThanDays * 86400));
        $stmt = $this->db->pdo()->prepare('DELETE FROM api_audit_log WHERE created_at < :cutoff');
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }
}
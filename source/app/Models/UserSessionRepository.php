<?php

declare(strict_types=1);

namespace Latch\Models;

use Latch\Core\Database;

final class UserSessionRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function register(
        string $sessionId,
        int $userId,
        string $fingerprint,
        string $ip,
        string $userAgent,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO user_sessions (id, user_id, fingerprint, ip_address, user_agent, last_seen_at, created_at)
             VALUES (:id, :user_id, :fingerprint, :ip, :user_agent, :last_seen, :created_at)
             ON CONFLICT(id) DO UPDATE SET
                last_seen_at = excluded.last_seen_at,
                ip_address = excluded.ip_address,
                user_agent = excluded.user_agent,
                revoked_at = NULL'
        );
        $now = gmdate('c');
        $stmt->execute([
            'id' => $sessionId,
            'user_id' => $userId,
            'fingerprint' => $fingerprint,
            'ip' => $ip,
            'user_agent' => mb_substr($userAgent, 0, 512),
            'last_seen' => $now,
            'created_at' => $now,
        ]);
    }

    public function touch(string $sessionId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE user_sessions SET last_seen_at = :last_seen WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute(['last_seen' => gmdate('c'), 'id' => $sessionId]);
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM user_sessions
             WHERE user_id = :user_id AND revoked_at IS NULL
             ORDER BY last_seen_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function revoke(string $sessionId, int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE user_sessions SET revoked_at = :revoked_at
             WHERE id = :id AND user_id = :user_id AND revoked_at IS NULL'
        );
        $stmt->execute([
            'revoked_at' => gmdate('c'),
            'id' => $sessionId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function revokeAllExcept(int $userId, string $exceptSessionId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE user_sessions SET revoked_at = :revoked_at
             WHERE user_id = :user_id AND id != :except AND revoked_at IS NULL'
        );
        $stmt->execute([
            'revoked_at' => gmdate('c'),
            'user_id' => $userId,
            'except' => $exceptSessionId,
        ]);

        return $stmt->rowCount();
    }

    public function revokeAllForUser(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE user_sessions SET revoked_at = :revoked_at
             WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $stmt->execute(['revoked_at' => gmdate('c'), 'user_id' => $userId]);

        return $stmt->rowCount();
    }

    public function isRevoked(string $sessionId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT revoked_at FROM user_sessions WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $sessionId]);
        $revoked = $stmt->fetchColumn();

        return $revoked !== false && $revoked !== null;
    }

    public function pruneStale(int $days = 90): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM user_sessions WHERE last_seen_at < :cutoff'
        );
        $stmt->execute(['cutoff' => gmdate('c', time() - $days * 86400)]);

        return $stmt->rowCount();
    }
}
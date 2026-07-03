<?php

declare(strict_types=1);

namespace Latch\Models;

use Latch\Core\Database;

final class EmailVerificationRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(int $userId, string $email, string $token, int $ttlHours = 48): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO email_verifications (user_id, email, token_hash, expires_at, created_at)
             VALUES (:user_id, :email, :token_hash, :expires_at, :created_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'email' => strtolower(trim($email)),
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('c', time() + $ttlHours * 3600),
            'created_at' => gmdate('c'),
        ]);
    }

    public function findValid(string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ev.*, u.username
             FROM email_verifications ev
             JOIN users u ON u.id = ev.user_id
             WHERE ev.token_hash = :hash
               AND ev.verified_at IS NULL
               AND ev.expires_at > :now
             LIMIT 1'
        );
        $stmt->execute([
            'hash' => hash('sha256', $token),
            'now' => gmdate('c'),
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function markVerified(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE email_verifications SET verified_at = :verified_at WHERE id = :id'
        );
        $stmt->execute(['verified_at' => gmdate('c'), 'id' => $id]);
    }

    public function pruneExpired(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM email_verifications WHERE expires_at < :cutoff OR verified_at IS NOT NULL'
        );
        $stmt->execute(['cutoff' => gmdate('c', time() - 86400 * 7)]);

        return $stmt->rowCount();
    }
}
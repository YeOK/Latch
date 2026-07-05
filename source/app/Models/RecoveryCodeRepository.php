<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Models;

use Latch\Core\Database;

final class RecoveryCodeRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function countUnused(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = :user_id AND used_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<string> Plaintext codes (shown once to the user).
     */
    public function replaceForUser(int $userId, int $count = 8): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = :user_id')->execute(['user_id' => $userId]);

        $codes = [];
        $now = gmdate('c');
        $stmt = $pdo->prepare(
            'INSERT INTO user_recovery_codes (user_id, code_hash, created_at) VALUES (:user_id, :code_hash, :created_at)'
        );

        for ($i = 0; $i < $count; $i++) {
            $plain = $this->generatePlainCode();
            $codes[] = $plain;
            $stmt->execute([
                'user_id' => $userId,
                'code_hash' => password_hash($plain, PASSWORD_DEFAULT),
                'created_at' => $now,
            ]);
        }

        return $codes;
    }

    public function verifyAndConsume(int $userId, string $code): bool
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $code) ?? '');
        if ($normalized === '') {
            return false;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code_hash FROM user_recovery_codes
             WHERE user_id = :user_id AND used_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId]);

        foreach ($stmt->fetchAll() as $row) {
            if (!password_verify($normalized, (string) $row['code_hash'])) {
                continue;
            }

            $update = $this->db->pdo()->prepare(
                'UPDATE user_recovery_codes SET used_at = :used_at WHERE id = :id AND used_at IS NULL'
            );
            $update->execute([
                'used_at' => gmdate('c'),
                'id' => (int) $row['id'],
            ]);

            return $update->rowCount() === 1;
        }

        return false;
    }

    public function deleteForUser(int $userId): void
    {
        $this->db->pdo()->prepare('DELETE FROM user_recovery_codes WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);
    }

    private function generatePlainCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $segments = [];
        for ($s = 0; $s < 2; $s++) {
            $part = '';
            for ($i = 0; $i < 4; $i++) {
                $part .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $segments[] = $part;
        }

        return implode('-', $segments);
    }
}
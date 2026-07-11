<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Models;

use Latch\Core\Database;

final class MailQueueRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function tableExists(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'mail_queue' LIMIT 1"
        );
        $stmt->execute();
        $exists = (bool) $stmt->fetchColumn();

        return $exists;
    }

    public function enqueue(string $recipient, string $subject, string $body): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO mail_queue (recipient, subject, body, queued_at)
             VALUES (:recipient, :subject, :body, :queued_at)'
        );
        $stmt->execute([
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'queued_at' => gmdate('c'),
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return list<array{id: int, recipient: string, subject: string, body: string, attempts: int}>
     */
    public function fetchPending(int $limit, int $maxAttempts): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, recipient, subject, body, attempts
             FROM mail_queue
             WHERE sent_at IS NULL AND attempts < :max_attempts
             ORDER BY queued_at ASC, id ASC
             LIMIT :limit'
        );
        $stmt->bindValue('max_attempts', max(1, $maxAttempts), \PDO::PARAM_INT);
        $stmt->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'recipient' => (string) $row['recipient'],
                'subject' => (string) $row['subject'],
                'body' => (string) $row['body'],
                'attempts' => (int) $row['attempts'],
            ];
        }

        return $rows;
    }

    public function markSent(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE mail_queue SET sent_at = :sent_at WHERE id = :id'
        );
        $stmt->execute([
            'sent_at' => gmdate('c'),
            'id' => $id,
        ]);
    }

    public function recordFailure(int $id, string $error): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE mail_queue
             SET attempts = attempts + 1, last_error = :last_error
             WHERE id = :id'
        );
        $stmt->execute([
            'last_error' => $error,
            'id' => $id,
        ]);
    }

    public function pendingCount(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        $stmt = $this->db->pdo()->query(
            'SELECT COUNT(*) FROM mail_queue WHERE sent_at IS NULL'
        );

        return (int) $stmt->fetchColumn();
    }

    public function pruneSent(int $retainDays = 7): int
    {
        if (!$this->tableExists() || $retainDays <= 0) {
            return 0;
        }

        $cutoff = gmdate('c', time() - ($retainDays * 86400));
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM mail_queue WHERE sent_at IS NOT NULL AND sent_at < :cutoff'
        );
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    public function pruneDead(int $maxAttempts, int $retainDays = 30): int
    {
        if (!$this->tableExists() || $maxAttempts <= 0) {
            return 0;
        }

        $cutoff = gmdate('c', time() - (max(1, $retainDays) * 86400));
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM mail_queue
             WHERE sent_at IS NULL
               AND attempts >= :max_attempts
               AND queued_at < :cutoff'
        );
        $stmt->execute([
            'max_attempts' => $maxAttempts,
            'cutoff' => $cutoff,
        ]);

        return $stmt->rowCount();
    }
}
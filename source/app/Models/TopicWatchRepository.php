<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Models;

use Latch\Core\Database;
use Latch\Support\DeletedAuthorSql;

final class TopicWatchRepository
{
    private const LATEST_APPROVED_POST_AT_SQL = <<<'SQL'
        (SELECT MAX(p.created_at)
         FROM posts p
         WHERE p.topic_id = t.id
           AND p.deleted_at IS NULL
           AND p.approval_status = 'approved')
        SQL;

    public function __construct(private readonly Database $db)
    {
    }

    public function isWatching(int $userId, int $topicId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM topic_watches WHERE user_id = :user_id AND topic_id = :topic_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'topic_id' => $topicId]);

        return (bool) $stmt->fetchColumn();
    }

    public function watch(int $userId, int $topicId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT OR IGNORE INTO topic_watches (user_id, topic_id, created_at)
             VALUES (:user_id, :topic_id, :created_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'topic_id' => $topicId,
            'created_at' => gmdate('c'),
        ]);
    }

    public function unwatch(int $userId, int $topicId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM topic_watches WHERE user_id = :user_id AND topic_id = :topic_id'
        );
        $stmt->execute(['user_id' => $userId, 'topic_id' => $topicId]);
    }

    public function toggleWatch(int $userId, int $topicId): bool
    {
        if ($this->isWatching($userId, $topicId)) {
            $this->unwatch($userId, $topicId);

            return false;
        }

        $this->watch($userId, $topicId);

        return true;
    }

    public function markRead(int $userId, int $topicId, int $lastReadPostId, string $lastReadAt): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO topic_reads (user_id, topic_id, last_read_post_id, last_read_at)
             VALUES (:user_id, :topic_id, :last_read_post_id, :last_read_at)
             ON CONFLICT(user_id, topic_id) DO UPDATE SET
                last_read_post_id = excluded.last_read_post_id,
                last_read_at = excluded.last_read_at'
        );
        $stmt->execute([
            'user_id' => $userId,
            'topic_id' => $topicId,
            'last_read_post_id' => $lastReadPostId,
            'last_read_at' => $lastReadAt,
        ]);
    }

    /**
     * Per-topic unread flags using the same rules as board unread counts.
     *
     * @param list<int> $topicIds
     * @return array<int, bool>
     */
    public function unreadFlagsForTopics(int $userId, array $topicIds): array
    {
        $topicIds = array_values(array_unique(array_filter($topicIds, static fn (int $id): bool => $id > 0)));
        if ($topicIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($topicIds), '?'));
        $latestSql = self::LATEST_APPROVED_POST_AT_SQL;
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.id AS topic_id,
                    CASE
                        WHEN {$latestSql} IS NULL THEN 0
                        WHEN tr.last_read_at IS NULL THEN 1
                        WHEN {$latestSql} > tr.last_read_at THEN 1
                        ELSE 0
                    END AS is_unread
             FROM topics t
             LEFT JOIN topic_reads tr ON tr.topic_id = t.id AND tr.user_id = ?
             WHERE t.id IN ({$placeholders}) AND t.deleted_at IS NULL
               AND EXISTS (
                    SELECT 1 FROM posts p
                    WHERE p.topic_id = t.id AND p.deleted_at IS NULL AND p.approval_status = 'approved'
               )"
        );
        $stmt->execute(array_merge([$userId], $topicIds));

        $flags = array_fill_keys($topicIds, false);
        foreach ($stmt->fetchAll() as $row) {
            $flags[(int) $row['topic_id']] = (int) $row['is_unread'] === 1;
        }

        return $flags;
    }

    /**
     * @param list<int> $boardIds
     * @return array<int, int>
     */
    public function unreadCountsForBoards(int $userId, array $boardIds): array
    {
        $boardIds = array_values(array_unique(array_filter($boardIds, static fn (int $id): bool => $id > 0)));
        if ($boardIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($boardIds), '?'));
        $latestSql = self::LATEST_APPROVED_POST_AT_SQL;
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.board_id, COUNT(*) AS unread_count
             FROM topics t
             LEFT JOIN topic_reads tr ON tr.topic_id = t.id AND tr.user_id = ?
             WHERE t.deleted_at IS NULL
               AND t.board_id IN ({$placeholders})
               AND {$latestSql} IS NOT NULL
               AND (tr.last_read_at IS NULL OR {$latestSql} > tr.last_read_at)
               AND EXISTS (
                    SELECT 1 FROM posts p
                    WHERE p.topic_id = t.id AND p.deleted_at IS NULL AND p.approval_status = 'approved'
               )
             GROUP BY t.board_id"
        );
        $stmt->execute(array_merge([$userId], $boardIds));

        $counts = array_fill_keys($boardIds, 0);
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['board_id']] = (int) $row['unread_count'];
        }

        return $counts;
    }

    public function countUnreadWatched(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
             FROM topic_watches tw
             JOIN topics t ON t.id = tw.topic_id
             JOIN topic_reads tr ON tr.topic_id = t.id AND tr.user_id = tw.user_id
             WHERE tw.user_id = :user_id
               AND t.deleted_at IS NULL
               AND " . self::LATEST_APPROVED_POST_AT_SQL . " > tr.last_read_at
               AND EXISTS (
                    SELECT 1 FROM posts p
                    WHERE p.topic_id = t.id AND p.deleted_at IS NULL AND p.approval_status = 'approved'
               )"
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listWatchedTopics(int $userId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.*, ' . DeletedAuthorSql::authorName() . ", u.email AS author_email,
                    b.slug AS board_slug, b.name AS board_name,
                    tr.last_read_at,
                    (SELECT COUNT(*) FROM posts p
                     WHERE p.topic_id = t.id AND p.deleted_at IS NULL AND p.approval_status = 'approved') AS post_count,
                    CASE WHEN tr.last_read_at IS NOT NULL AND " . self::LATEST_APPROVED_POST_AT_SQL . " > tr.last_read_at THEN 1 ELSE 0 END AS is_unread
             FROM topic_watches tw
             JOIN topics t ON t.id = tw.topic_id
             LEFT JOIN users u ON u.id = t.user_id
             JOIN boards b ON b.id = t.board_id
             LEFT JOIN topic_reads tr ON tr.topic_id = t.id AND tr.user_id = tw.user_id
             WHERE tw.user_id = :user_id AND t.deleted_at IS NULL
               AND EXISTS (
                    SELECT 1 FROM posts p
                    WHERE p.topic_id = t.id AND p.deleted_at IS NULL AND p.approval_status = 'approved'
               )
             ORDER BY is_unread DESC, t.last_post_at DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue('user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', max(1, min(100, $limit)), \PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countWatched(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
             FROM topic_watches tw
             JOIN topics t ON t.id = tw.topic_id
             WHERE tw.user_id = :user_id AND t.deleted_at IS NULL
               AND EXISTS (
                    SELECT 1 FROM posts p
                    WHERE p.topic_id = t.id AND p.deleted_at IS NULL AND p.approval_status = 'approved'
               )"
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }
}
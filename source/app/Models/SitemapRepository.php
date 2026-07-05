<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Models;

use Latch\Core\BoardAcl;
use Latch\Core\Database;
use PDO;

final class SitemapRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Boards visible to anonymous crawlers (guest-readable, not deleted).
     *
     * @return list<array<string, mixed>>
     */
    public function publicBoards(bool $membersOnly): array
    {
        if ($membersOnly) {
            return [];
        }

        $accessSql = BoardAcl::sqlBoardReadFilter(false, null);

        $stmt = $this->db->pdo()->query(
            "SELECT b.slug, b.name,
                    COALESCE(MAX(t.last_post_at), '') AS last_post_at
             FROM boards b
             LEFT JOIN topics t ON t.board_id = b.id AND t.deleted_at IS NULL
             WHERE 1=1{$accessSql}
             GROUP BY b.id
             ORDER BY b.sort_order ASC, b.name ASC"
        );

        return $stmt->fetchAll();
    }

    /**
     * Topics visible to anonymous crawlers with an approved first post.
     *
     * @return list<array<string, mixed>>
     */
    public function publicTopics(bool $membersOnly, int $limit): array
    {
        if ($membersOnly) {
            return [];
        }

        $accessSql = BoardAcl::sqlBoardReadFilter(false, null);

        $stmt = $this->db->pdo()->prepare(
            "SELECT t.id, t.last_post_at
             FROM topics t
             JOIN boards b ON b.id = t.board_id
             WHERE t.deleted_at IS NULL{$accessSql}
               AND EXISTS (
                   SELECT 1 FROM posts p
                   WHERE p.topic_id = t.id
                     AND p.deleted_at IS NULL
                     AND p.quarantined_at IS NULL
                     AND p.approval_status = 'approved'
               )
             ORDER BY t.last_post_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
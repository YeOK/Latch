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
use Latch\Core\PostFormatter;
use Latch\Core\RssFeed;
use Latch\Support\DeletedAuthorSql;
use PDO;

final class RssRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly PostFormatter $formatter,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentTopicsForSite(int $limit, bool $loggedIn, bool $membersOnly, ?string $userRole = null): array
    {
        if ($membersOnly && !$loggedIn) {
            return [];
        }

        $accessSql = BoardAcl::sqlBoardReadFilter($loggedIn, $userRole);

        $stmt = $this->db->pdo()->prepare(
            "SELECT t.id, t.title, t.slug, t.last_post_at,
                    b.slug AS board_slug, b.name AS board_name,
                    " . DeletedAuthorSql::authorName() . ",
                    fp.body AS first_post_body
             FROM topics t
             JOIN boards b ON b.id = t.board_id
             LEFT JOIN users u ON u.id = t.user_id
             JOIN posts fp ON fp.id = (
                 SELECT p.id FROM posts p
                 WHERE p.topic_id = t.id AND p.deleted_at IS NULL AND p.quarantined_at IS NULL
                   AND p.approval_status = 'approved'
                 ORDER BY p.created_at ASC LIMIT 1
             )
             WHERE t.deleted_at IS NULL{$accessSql}
             ORDER BY t.last_post_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentTopicsForBoard(int $boardId, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.id, t.title, t.slug, t.last_post_at,
                    b.slug AS board_slug, b.name AS board_name,
                    " . DeletedAuthorSql::authorName() . ",
                    fp.body AS first_post_body
             FROM topics t
             JOIN boards b ON b.id = t.board_id
             LEFT JOIN users u ON u.id = t.user_id
             JOIN posts fp ON fp.id = (
                 SELECT p.id FROM posts p
                 WHERE p.topic_id = t.id AND p.deleted_at IS NULL AND p.quarantined_at IS NULL
                   AND p.approval_status = 'approved'
                 ORDER BY p.created_at ASC LIMIT 1
             )
             WHERE t.board_id = :board_id AND t.deleted_at IS NULL
             ORDER BY t.last_post_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue('board_id', $boardId, PDO::PARAM_INT);
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function postsForTopic(int $topicId, bool $staffView): array
    {
        $visibilitySql = $staffView ? '' : " AND p.quarantined_at IS NULL AND p.approval_status = 'approved'";

        $stmt = $this->db->pdo()->prepare(
            "SELECT p.id, p.body, p.created_at, p.quarantined_at,
                    t.title AS topic_title, t.id AS topic_id,
                    b.slug AS board_slug,
                    " . DeletedAuthorSql::authorName() . "
             FROM posts p
             JOIN topics t ON t.id = p.topic_id
             JOIN boards b ON b.id = t.board_id
             LEFT JOIN users u ON u.id = p.user_id
             WHERE p.topic_id = :topic_id AND p.deleted_at IS NULL AND t.deleted_at IS NULL{$visibilitySql}
             ORDER BY p.created_at ASC"
        );
        $stmt->execute(['topic_id' => $topicId]);

        return $stmt->fetchAll();
    }

    public function plainExcerpt(string $rawBody, int $maxLength = 400): string
    {
        return RssFeed::excerpt($this->formatter->plainText($rawBody), $maxLength);
    }
}
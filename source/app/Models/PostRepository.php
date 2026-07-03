<?php

declare(strict_types=1);

namespace Latch\Models;

use Latch\Core\BoardAcl;
use Latch\Core\Database;
use Latch\Core\InputValidator;
use Latch\Support\Schema;
use RuntimeException;

final class PostRepository
{
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_PENDING = 'pending';
    public const APPROVAL_REJECTED = 'rejected';

    public function __construct(
        private readonly Database $db,
        private readonly ?InputValidator $input = null,
    ) {
    }

    private function excludeTrashedSql(string $prefix = ''): string
    {
        if (!Schema::postsHaveTrashQueue($this->db)) {
            return '';
        }

        return ' AND ' . $prefix . 'trashed_at IS NULL';
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM posts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function reassignTopic(int $fromTopicId, int $toTopicId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE posts SET topic_id = :to_topic_id
             WHERE topic_id = :from_topic_id AND deleted_at IS NULL'
        );
        $stmt->execute([
            'from_topic_id' => $fromTopicId,
            'to_topic_id' => $toTopicId,
        ]);

        return $stmt->rowCount();
    }

    /**
     * @param list<int> $postIds
     */
    public function reassignPosts(array $postIds, int $toTopicId): int
    {
        $postIds = array_values(array_unique(array_filter($postIds, static fn (int $id): bool => $id > 0)));
        if ($postIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "UPDATE posts SET topic_id = ?
             WHERE id IN ({$placeholders}) AND deleted_at IS NULL"
        );
        $params = array_merge([$toTopicId], $postIds);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveByTopicOrdered(int $topicId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM posts
             WHERE topic_id = :topic_id AND deleted_at IS NULL' . $this->excludeTrashedSql() . '
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['topic_id' => $topicId]);

        return $stmt->fetchAll();
    }

    public function listByTopic(
        int $topicId,
        bool $includeDeleted = false,
        ?int $viewerUserId = null,
        bool $isMod = false,
        bool $includeTrashed = false,
    ): array {
        $viewerJoin = '';
        if ($viewerUserId !== null) {
            $viewerJoin = ' LEFT JOIN post_reactions pr ON pr.post_id = p.id AND pr.user_id = :viewer_vote_user_id';
        }

        $sql = 'SELECT p.*, u.username AS author_name, u.email AS author_email'
            . ($viewerUserId !== null ? ', pr.vote AS viewer_vote' : '')
            . ' FROM posts p
                JOIN users u ON u.id = p.user_id'
            . $viewerJoin
            . ' WHERE p.topic_id = :topic_id';

        if (!$includeDeleted) {
            $sql .= ' AND p.deleted_at IS NULL';
            if (!$includeTrashed) {
                $sql .= $this->excludeTrashedSql('p.');
            }
        }

        if (!$isMod) {
            $sql .= ' AND (p.approval_status = :approved';
            if ($viewerUserId !== null) {
                $sql .= ' OR (p.approval_status = :pending AND p.user_id = :viewer_user_id)';
            }
            $sql .= ')';
        }

        $sql .= ' ORDER BY p.created_at ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $params = ['topic_id' => $topicId];
        if ($viewerUserId !== null) {
            $params['viewer_vote_user_id'] = $viewerUserId;
        }
        if (!$isMod) {
            $params['approved'] = self::APPROVAL_APPROVED;
            if ($viewerUserId !== null) {
                $params['pending'] = self::APPROVAL_PENDING;
                $params['viewer_user_id'] = $viewerUserId;
            }
        }
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function create(
        int $topicId,
        int $userId,
        string $body,
        ?string $createdAt = null,
        string $approvalStatus = self::APPROVAL_APPROVED,
    ): array {
        $body = trim($body);
        $this->input?->assertPostBody($body);

        if (!in_array($approvalStatus, [self::APPROVAL_APPROVED, self::APPROVAL_PENDING, self::APPROVAL_REJECTED], true)) {
            throw new RuntimeException('Invalid approval status.');
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO posts (topic_id, user_id, body, created_at, approval_status)
             VALUES (:topic_id, :user_id, :body, :created_at, :approval_status)'
        );
        $stmt->execute([
            'topic_id' => $topicId,
            'user_id' => $userId,
            'body' => $body,
            'created_at' => $createdAt ?? gmdate('c'),
            'approval_status' => $approvalStatus,
        ]);

        return $this->findById((int) $this->db->pdo()->lastInsertId()) ?? [];
    }

    public function updateBody(int $id, string $body): void
    {
        $body = trim($body);
        $this->input?->assertPostBody($body);

        $stmt = $this->db->pdo()->prepare(
            'UPDATE posts SET body = :body, updated_at = :updated_at
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            'body' => $body,
            'updated_at' => gmdate('c'),
            'id' => $id,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Post not found or removed.');
        }
    }

    public function countApprovedByUser(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM posts
             WHERE user_id = :user_id AND deleted_at IS NULL AND approval_status = :approved"
        );
        $stmt->execute(['user_id' => $userId, 'approved' => self::APPROVAL_APPROVED]);

        return (int) $stmt->fetchColumn();
    }

    public function countAll(): int
    {
        $stmt = $this->db->pdo()->query(
            'SELECT COUNT(*) FROM posts WHERE deleted_at IS NULL'
        );

        return (int) $stmt->fetchColumn();
    }

    public function countPending(): int
    {
        $stmt = $this->db->pdo()->query(
            "SELECT COUNT(*) FROM posts
             WHERE approval_status = 'pending' AND deleted_at IS NULL"
        );

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPending(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.*, u.username AS author_name,
                    t.title AS topic_title, t.id AS topic_id,
                    b.slug AS board_slug, b.name AS board_name
             FROM posts p
             JOIN users u ON u.id = p.user_id
             JOIN topics t ON t.id = p.topic_id AND t.deleted_at IS NULL
             JOIN boards b ON b.id = t.board_id
             WHERE p.approval_status = :pending AND p.deleted_at IS NULL
             ORDER BY p.created_at ASC
             LIMIT :limit"
        );
        $stmt->bindValue('pending', self::APPROVAL_PENDING);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function approve(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE posts SET approval_status = :approved
             WHERE id = :id AND approval_status = :pending AND deleted_at IS NULL"
        );
        $stmt->execute([
            'approved' => self::APPROVAL_APPROVED,
            'pending' => self::APPROVAL_PENDING,
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function reject(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE posts SET approval_status = :rejected, deleted_at = :deleted_at
             WHERE id = :id AND approval_status = :pending AND deleted_at IS NULL"
        );
        $stmt->execute([
            'rejected' => self::APPROVAL_REJECTED,
            'pending' => self::APPROVAL_PENDING,
            'deleted_at' => gmdate('c'),
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param list<int> $topicIds
     * @return array<int, string>
     */
    public function latestApprovedPostAtByTopics(array $topicIds): array
    {
        $topicIds = array_values(array_unique(array_filter($topicIds, static fn (int $id): bool => $id > 0)));
        if ($topicIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($topicIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT topic_id, MAX(created_at) AS latest_at
             FROM posts
             WHERE topic_id IN ({$placeholders})
               AND deleted_at IS NULL
               AND approval_status = ?
             GROUP BY topic_id"
        );
        $stmt->execute(array_merge($topicIds, [self::APPROVAL_APPROVED]));

        $latest = [];
        foreach ($stmt->fetchAll() as $row) {
            $latest[(int) $row['topic_id']] = (string) $row['latest_at'];
        }

        return $latest;
    }

    public function topicHasApprovedPost(int $topicId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM posts
             WHERE topic_id = :topic_id AND deleted_at IS NULL AND approval_status = :approved
             LIMIT 1"
        );
        $stmt->execute(['topic_id' => $topicId, 'approved' => self::APPROVAL_APPROVED]);

        return (bool) $stmt->fetchColumn();
    }

    public function softDelete(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE posts SET deleted_at = :deleted_at WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['deleted_at' => gmdate('c'), 'id' => $id]);
    }

    public function trash(int $id, int $staffUserId, int $topicId, int $boardId): bool
    {
        if (!Schema::postsHaveTrashQueue($this->db)) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE posts
             SET trashed_at = :trashed_at,
                 trashed_by_user_id = :staff_id,
                 trash_restore_topic_id = :topic_id,
                 trash_restore_board_id = :board_id,
                 quarantined_at = NULL,
                 quarantined_by_report_id = NULL
             WHERE id = :id AND deleted_at IS NULL AND trashed_at IS NULL'
        );
        $stmt->execute([
            'trashed_at' => gmdate('c'),
            'staff_id' => $staffUserId,
            'topic_id' => $topicId,
            'board_id' => $boardId,
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function restoreFromTrash(int $id): bool
    {
        if (!Schema::postsHaveTrashQueue($this->db)) {
            return false;
        }

        $post = $this->findById($id);
        if ($post === null || ($post['trashed_at'] ?? null) === null || ($post['deleted_at'] ?? null) !== null) {
            return false;
        }

        $restoreTopicId = (int) ($post['trash_restore_topic_id'] ?? 0);
        if ($restoreTopicId <= 0) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE posts
             SET trashed_at = NULL,
                 trashed_by_user_id = NULL,
                 trash_restore_topic_id = NULL,
                 trash_restore_board_id = NULL,
                 topic_id = :restore_topic_id
             WHERE id = :id AND trashed_at IS NOT NULL AND deleted_at IS NULL'
        );
        $stmt->execute([
            'id' => $id,
            'restore_topic_id' => $restoreTopicId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function purgeFromTrash(int $id): bool
    {
        if (!Schema::postsHaveTrashQueue($this->db)) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE posts
             SET deleted_at = :deleted_at,
                 trashed_at = NULL,
                 trashed_by_user_id = NULL,
                 trash_restore_topic_id = NULL,
                 trash_restore_board_id = NULL
             WHERE id = :id AND trashed_at IS NOT NULL AND deleted_at IS NULL'
        );
        $stmt->execute([
            'deleted_at' => gmdate('c'),
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function isTrashed(int $id): bool
    {
        if (!Schema::postsHaveTrashQueue($this->db)) {
            return false;
        }

        $post = $this->findById($id);

        return $post !== null
            && ($post['trashed_at'] ?? null) !== null
            && ($post['deleted_at'] ?? null) === null;
    }

    public function countTrashed(): int
    {
        if (!Schema::postsHaveTrashQueue($this->db)) {
            return 0;
        }

        return (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM posts WHERE trashed_at IS NOT NULL AND deleted_at IS NULL'
        )->fetchColumn();
    }

    public function isQuarantined(int $id): bool
    {
        $post = $this->findById($id);

        return $post !== null && $post['quarantined_at'] !== null && $post['deleted_at'] === null;
    }

    public function quarantine(int $id, int $reportId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE posts SET quarantined_at = :quarantined_at, quarantined_by_report_id = :report_id
             WHERE id = :id AND deleted_at IS NULL' . $this->excludeTrashedSql()
        );
        $stmt->execute([
            'quarantined_at' => gmdate('c'),
            'report_id' => $reportId > 0 ? $reportId : null,
            'id' => $id,
        ]);
    }

    public function staffQuarantine(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE posts SET quarantined_at = :quarantined_at, quarantined_by_report_id = NULL
             WHERE id = :id AND deleted_at IS NULL' . $this->excludeTrashedSql() . ' AND quarantined_at IS NULL'
        );
        $stmt->execute([
            'quarantined_at' => gmdate('c'),
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function liftQuarantine(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE posts SET quarantined_at = NULL, quarantined_by_report_id = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function staffLiftQuarantine(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE posts
             SET quarantined_at = NULL, quarantined_by_report_id = NULL
             WHERE id = :id AND quarantined_at IS NOT NULL AND deleted_at IS NULL' . $this->excludeTrashedSql()
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function countQuarantined(): int
    {
        return (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM posts
             WHERE quarantined_at IS NOT NULL AND deleted_at IS NULL' . $this->excludeTrashedSql()
        )->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listQuarantined(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.*,
                    u.username AS author_name,
                    t.title AS topic_title,
                    b.slug AS board_slug,
                    b.name AS board_name,
                    r.reason_code AS report_reason_code,
                    r.severity AS report_severity
             FROM posts p
             JOIN users u ON u.id = p.user_id
             JOIN topics t ON t.id = p.topic_id AND t.deleted_at IS NULL
             JOIN boards b ON b.id = t.board_id
             LEFT JOIN reports r ON r.id = p.quarantined_by_report_id
             WHERE p.quarantined_at IS NOT NULL AND p.deleted_at IS NULL" . $this->excludeTrashedSql('p.') . "
             ORDER BY p.quarantined_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function listByUser(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id, p.topic_id, p.body, p.created_at, p.updated_at, p.deleted_at
             FROM posts p
             WHERE p.user_id = :user_id
             ORDER BY p.created_at ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentPublicByUser(int $userId, int $limit, bool $loggedIn, bool $isMod, ?string $userRole = null): array
    {
        $limit = max(1, min(50, $limit));
        $accessSql = BoardAcl::sqlBoardReadFilter($loggedIn, $userRole);
        $quarantineSql = $isMod ? '' : ' AND p.quarantined_at IS NULL';

        $stmt = $this->db->pdo()->prepare(
            "SELECT p.id, p.topic_id, p.body, p.created_at, p.quarantined_at,
                    t.title AS topic_title,
                    b.slug AS board_slug, b.name AS board_name
             FROM posts p
             JOIN topics t ON t.id = p.topic_id AND t.deleted_at IS NULL
             JOIN boards b ON b.id = t.board_id
             WHERE p.user_id = :user_id AND p.deleted_at IS NULL{$accessSql}{$quarantineSql}
             ORDER BY p.created_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue('user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return list<int>
     */
    public function distinctAuthorIdsForBoard(int $boardId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT p.user_id
             FROM posts p
             JOIN topics t ON t.id = p.topic_id
             WHERE t.board_id = :board_id
               AND t.deleted_at IS NULL
               AND p.deleted_at IS NULL'
        );
        $stmt->execute(['board_id' => $boardId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Replace post bodies when a user deletes their account (privacy).
     *
     * @return list<int> topic IDs touched
     */
    public function anonymiseContentByUser(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT topic_id FROM posts
             WHERE user_id = :user_id AND deleted_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId]);
        $topicIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $update = $this->db->pdo()->prepare(
            "UPDATE posts SET body = '[deleted]', updated_at = :updated_at
             WHERE user_id = :user_id AND deleted_at IS NULL"
        );
        $update->execute([
            'user_id' => $userId,
            'updated_at' => gmdate('c'),
        ]);

        return $topicIds;
    }
}
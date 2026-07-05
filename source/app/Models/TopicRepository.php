<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Models;

use Latch\Core\Database;
use Latch\Core\InputValidator;
use Latch\Support\DeletedAuthorSql;
use Latch\Support\Str;
use RuntimeException;

final class TopicRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly PostRepository $posts,
        private readonly ?InputValidator $input = null,
    ) {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM topics WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByIdWithAuthor(int $id, bool $isMod = false): ?array
    {
        $postCountSql = $isMod
            ? '(SELECT COUNT(*) FROM posts p WHERE p.topic_id = t.id AND p.deleted_at IS NULL)'
            : "(SELECT COUNT(*) FROM posts p WHERE p.topic_id = t.id AND p.deleted_at IS NULL AND p.approval_status = 'approved')";

        $stmt = $this->db->pdo()->prepare(
            'SELECT t.*, ' . DeletedAuthorSql::authorName() . ", {$postCountSql} AS post_count
             FROM topics t
             LEFT JOIN users u ON u.id = t.user_id
             WHERE t.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findBySlug(int $boardId, string $slug): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM topics WHERE board_id = :board_id AND slug = :slug LIMIT 1'
        );
        $stmt->execute(['board_id' => $boardId, 'slug' => $slug]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function listByBoard(
        int $boardId,
        int $page,
        int $perPage,
        ?string $tagSlug = null,
        bool $isMod = false,
        string $sort = 'activity',
    ): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $tagJoin = '';
        $tagWhere = '';
        $approvalWhere = '';

        if ($tagSlug !== null && $tagSlug !== '') {
            $tagJoin = 'JOIN topic_tags tt ON tt.topic_id = t.id JOIN tags tg ON tg.id = tt.tag_id';
            $tagWhere = ' AND tg.slug = :tag_slug';
        }

        if (!$isMod) {
            $approvalWhere = " AND EXISTS (
                SELECT 1 FROM posts ap
                WHERE ap.topic_id = t.id AND ap.deleted_at IS NULL AND ap.approval_status = 'approved'
            )";
        }

        $postCountSql = $isMod
            ? '(SELECT COUNT(*) FROM posts p WHERE p.topic_id = t.id AND p.deleted_at IS NULL)'
            : "(SELECT COUNT(*) FROM posts p WHERE p.topic_id = t.id AND p.deleted_at IS NULL AND p.approval_status = 'approved')";

        $orderBy = \Latch\Support\TopicListSort::orderBySql($sort);

        $stmt = $this->db->pdo()->prepare(
            'SELECT t.*, ' . DeletedAuthorSql::authorName() . ", u.email AS author_email,
                    {$postCountSql} AS post_count
             FROM topics t
             LEFT JOIN users u ON u.id = t.user_id
             {$tagJoin}
             WHERE t.board_id = :board_id AND t.deleted_at IS NULL{$tagWhere}{$approvalWhere}
             ORDER BY {$orderBy}
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue('board_id', $boardId, \PDO::PARAM_INT);
        if ($tagSlug !== null && $tagSlug !== '') {
            $stmt->bindValue('tag_slug', $tagSlug);
        }
        $stmt->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countAll(): int
    {
        $stmt = $this->db->pdo()->query(
            'SELECT COUNT(*) FROM topics WHERE deleted_at IS NULL'
        );

        return (int) $stmt->fetchColumn();
    }

    public function countByBoard(int $boardId, ?string $tagSlug = null, bool $isMod = false): int
    {
        $tagJoin = '';
        $tagWhere = '';
        $approvalWhere = '';

        if ($tagSlug !== null && $tagSlug !== '') {
            $tagJoin = 'JOIN topic_tags tt ON tt.topic_id = topics.id JOIN tags tg ON tg.id = tt.tag_id';
            $tagWhere = ' AND tg.slug = :tag_slug';
        }

        if (!$isMod) {
            $approvalWhere = " AND EXISTS (
                SELECT 1 FROM posts ap
                WHERE ap.topic_id = topics.id AND ap.deleted_at IS NULL AND ap.approval_status = 'approved'
            )";
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM topics {$tagJoin}
             WHERE topics.board_id = :board_id AND topics.deleted_at IS NULL{$tagWhere}{$approvalWhere}"
        );
        $params = ['board_id' => $boardId];
        if ($tagSlug !== null && $tagSlug !== '') {
            $params['tag_slug'] = $tagSlug;
        }
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<int> $boardIds
     * @return array<int, array{topic_count: int, post_count: int, last_activity_at: ?string}>
     */
    public function activitySummariesForBoards(array $boardIds, bool $isMod = false): array
    {
        $boardIds = array_values(array_unique(array_filter($boardIds, static fn (int $id): bool => $id > 0)));
        if ($boardIds === []) {
            return [];
        }

        $approvalWhere = '';
        if (!$isMod) {
            $approvalWhere = " AND EXISTS (
                SELECT 1 FROM posts ap
                WHERE ap.topic_id = t.id AND ap.deleted_at IS NULL AND ap.approval_status = 'approved'
            )";
        }

        $postCountSql = $isMod
            ? '(SELECT COUNT(*) FROM posts p WHERE p.topic_id = t.id AND p.deleted_at IS NULL)'
            : "(SELECT COUNT(*) FROM posts p WHERE p.topic_id = t.id AND p.deleted_at IS NULL AND p.approval_status = 'approved')";

        $placeholders = implode(',', array_fill(0, count($boardIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.board_id,
                    COUNT(*) AS topic_count,
                    SUM({$postCountSql}) AS post_count,
                    MAX(t.last_post_at) AS last_activity_at
             FROM topics t
             WHERE t.board_id IN ({$placeholders}) AND t.deleted_at IS NULL{$approvalWhere}
             GROUP BY t.board_id"
        );
        $stmt->execute($boardIds);

        $summaries = [];
        foreach ($stmt->fetchAll() as $row) {
            $summaries[(int) $row['board_id']] = [
                'topic_count' => (int) $row['topic_count'],
                'post_count' => (int) $row['post_count'],
                'last_activity_at' => $row['last_activity_at'] !== null ? (string) $row['last_activity_at'] : null,
            ];
        }

        return $summaries;
    }

    /**
     * @param list<int> $boardIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function recentTopicsForBoards(array $boardIds, int $limitPerBoard = 4, bool $isMod = false): array
    {
        $boardIds = array_values(array_unique(array_filter($boardIds, static fn (int $id): bool => $id > 0)));
        $limitPerBoard = max(1, min(10, $limitPerBoard));
        if ($boardIds === []) {
            return [];
        }

        $approvalWhere = '';
        if (!$isMod) {
            $approvalWhere = " AND EXISTS (
                SELECT 1 FROM posts ap
                WHERE ap.topic_id = t.id AND ap.deleted_at IS NULL AND ap.approval_status = 'approved'
            )";
        }

        $postCountSql = $isMod
            ? '(SELECT COUNT(*) FROM posts p WHERE p.topic_id = t.id AND p.deleted_at IS NULL)'
            : "(SELECT COUNT(*) FROM posts p WHERE p.topic_id = t.id AND p.deleted_at IS NULL AND p.approval_status = 'approved')";

        $placeholders = implode(',', array_fill(0, count($boardIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT ranked.* FROM (
                SELECT t.*, " . DeletedAuthorSql::authorName() . ", u.email AS author_email,
                    {$postCountSql} AS post_count,
                    ROW_NUMBER() OVER (
                        PARTITION BY t.board_id
                        ORDER BY t.is_pinned DESC, t.last_post_at DESC
                    ) AS rn
                FROM topics t
                LEFT JOIN users u ON u.id = t.user_id
                WHERE t.board_id IN ({$placeholders}) AND t.deleted_at IS NULL{$approvalWhere}
            ) ranked
            WHERE ranked.rn <= ?
            ORDER BY ranked.board_id, ranked.rn"
        );

        foreach ($boardIds as $index => $boardId) {
            $stmt->bindValue($index + 1, $boardId, \PDO::PARAM_INT);
        }
        $stmt->bindValue(count($boardIds) + 1, $limitPerBoard, \PDO::PARAM_INT);
        $stmt->execute();

        $grouped = array_fill_keys($boardIds, []);
        foreach ($stmt->fetchAll() as $row) {
            unset($row['rn']);
            $grouped[(int) $row['board_id']][] = $row;
        }

        return $grouped;
    }

    public function create(
        int $boardId,
        int $userId,
        string $title,
        string $body,
        string $approvalStatus = PostRepository::APPROVAL_APPROVED,
    ): array {
        $title = trim($title);
        $body = trim($body);
        $this->input?->assertTopicTitle($title);
        $this->input?->assertPostBody($body);

        $slug = Str::uniqueSlug(
            $title,
            fn (string $s): bool => $this->findBySlug($boardId, $s) !== null
        );

        $now = gmdate('c');

        $this->db->begin();

        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO topics (board_id, user_id, title, slug, created_at, last_post_at)
                 VALUES (:board_id, :user_id, :title, :slug, :created_at, :last_post_at)'
            );
            $stmt->execute([
                'board_id' => $boardId,
                'user_id' => $userId,
                'title' => $title,
                'slug' => $slug,
                'created_at' => $now,
                'last_post_at' => $now,
            ]);

            $topicId = (int) $this->db->pdo()->lastInsertId();
            $this->posts->create($topicId, $userId, $body, $now, $approvalStatus);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->findById($topicId) ?? [];
    }

    public function touchLastPost(int $id, ?string $at = null): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE topics SET last_post_at = :at WHERE id = :id');
        $stmt->execute(['at' => $at ?? gmdate('c'), 'id' => $id]);
    }

    public function recalculateLastPostAt(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MAX(created_at) FROM posts
             WHERE topic_id = :topic_id AND deleted_at IS NULL AND approval_status = :approved'
        );
        $stmt->execute([
            'topic_id' => $id,
            'approved' => PostRepository::APPROVAL_APPROVED,
        ]);
        $lastAt = $stmt->fetchColumn();

        if (!is_string($lastAt) || $lastAt === '') {
            $topic = $this->findById($id);
            $lastAt = is_array($topic) ? (string) ($topic['created_at'] ?? gmdate('c')) : gmdate('c');
        }

        $this->touchLastPost($id, $lastAt);
    }

    public function setLocked(int $id, bool $locked): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE topics SET is_locked = :locked WHERE id = :id');
        $stmt->execute(['locked' => $locked ? 1 : 0, 'id' => $id]);
    }

    public function setPinned(int $id, bool $pinned): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE topics SET is_pinned = :pinned WHERE id = :id');
        $stmt->execute(['pinned' => $pinned ? 1 : 0, 'id' => $id]);
    }

    public function updateTitle(int $id, string $title): void
    {
        $title = trim($title);
        $this->input?->assertTopicTitle($title);

        $stmt = $this->db->pdo()->prepare('UPDATE topics SET title = :title WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['title' => $title, 'id' => $id]);
    }

    public function softDelete(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE topics SET deleted_at = :deleted_at, is_pinned = 0 WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['deleted_at' => gmdate('c'), 'id' => $id]);
    }

    /**
     * @return list<int> topic IDs updated
     */
    public function anonymiseTitlesByUser(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM topics WHERE user_id = :user_id AND deleted_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId]);
        $topicIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $update = $this->db->pdo()->prepare(
            "UPDATE topics SET title = '[deleted]'
             WHERE user_id = :user_id AND deleted_at IS NULL"
        );
        $update->execute(['user_id' => $userId]);

        return $topicIds;
    }

    /**
     * @return array{old_board_id: int, new_board_id: int}
     */
    public function moveToBoard(int $topicId, int $boardId): array
    {
        $topic = $this->findById($topicId);
        if ($topic === null || !empty($topic['deleted_at'])) {
            throw new RuntimeException('Topic not found.');
        }

        $oldBoardId = (int) $topic['board_id'];
        if ($oldBoardId === $boardId) {
            return ['old_board_id' => $oldBoardId, 'new_board_id' => $boardId];
        }

        $slug = Str::uniqueSlug(
            (string) $topic['title'],
            fn (string $candidate): bool => $this->findBySlug($boardId, $candidate) !== null,
        );

        $stmt = $this->db->pdo()->prepare(
            'UPDATE topics SET board_id = :board_id, slug = :slug WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            'board_id' => $boardId,
            'slug' => $slug,
            'id' => $topicId,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Could not move topic.');
        }

        return ['old_board_id' => $oldBoardId, 'new_board_id' => $boardId];
    }

    /**
     * @return array{source_id: int, target_id: int, posts_moved: int}
     */
    public function mergeInto(int $sourceTopicId, int $targetTopicId): array
    {
        if ($sourceTopicId === $targetTopicId) {
            throw new RuntimeException('Cannot merge a topic into itself.');
        }

        $source = $this->findById($sourceTopicId);
        $target = $this->findById($targetTopicId);
        if ($source === null || $target === null || !empty($source['deleted_at']) || !empty($target['deleted_at'])) {
            throw new RuntimeException('One or both topics were not found.');
        }

        $this->db->begin();

        try {
            $postsMoved = $this->posts->reassignTopic($sourceTopicId, $targetTopicId);

            $watchStmt = $this->db->pdo()->prepare(
                'INSERT OR IGNORE INTO topic_watches (user_id, topic_id, created_at)
                 SELECT user_id, :target_topic_id, created_at
                 FROM topic_watches
                 WHERE topic_id = :source_topic_id'
            );
            $watchStmt->execute([
                'target_topic_id' => $targetTopicId,
                'source_topic_id' => $sourceTopicId,
            ]);

            $this->db->pdo()->prepare('DELETE FROM topic_read_state WHERE topic_id = :topic_id')
                ->execute(['topic_id' => $sourceTopicId]);

            $this->softDelete($sourceTopicId);
            $this->recalculateLastPostAt($targetTopicId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'source_id' => $sourceTopicId,
            'target_id' => $targetTopicId,
            'posts_moved' => $postsMoved,
        ];
    }

    /**
     * @return array{source_id: int, new_topic_id: int, posts_moved: int}
     */
    public function splitFromPost(int $topicId, int $fromPostId, string $newTitle): array
    {
        $topic = $this->findById($topicId);
        if ($topic === null || !empty($topic['deleted_at'])) {
            throw new RuntimeException('Topic not found.');
        }

        $newTitle = trim($newTitle);
        $this->input?->assertTopicTitle($newTitle);

        $posts = $this->posts->listActiveByTopicOrdered($topicId);
        if ($posts === []) {
            throw new RuntimeException('Topic has no posts to split.');
        }

        $splitIndex = null;
        foreach ($posts as $index => $post) {
            if ((int) $post['id'] === $fromPostId) {
                $splitIndex = $index;
                break;
            }
        }

        if ($splitIndex === null) {
            throw new RuntimeException('Post not found in this topic.');
        }

        if ($splitIndex === 0) {
            throw new RuntimeException('Cannot split from the first post — use move or merge instead.');
        }

        $movingPosts = array_slice($posts, $splitIndex);
        $postIds = array_map(static fn (array $post): int => (int) $post['id'], $movingPosts);
        $firstMoved = $movingPosts[0];
        $lastPostAt = (string) $movingPosts[array_key_last($movingPosts)]['created_at'];

        $this->db->begin();

        try {
            $newTopic = $this->createShell(
                (int) $topic['board_id'],
                (int) $firstMoved['user_id'],
                $newTitle,
                $lastPostAt,
            );
            $newTopicId = (int) $newTopic['id'];
            $postsMoved = $this->posts->reassignPosts($postIds, $newTopicId);

            $this->recalculateLastPostAt($topicId);
            $this->recalculateLastPostAt($newTopicId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'source_id' => $topicId,
            'new_topic_id' => $newTopicId,
            'posts_moved' => $postsMoved,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createShell(int $boardId, int $userId, string $title, string $lastPostAt): array
    {
        $title = trim($title);
        $this->input?->assertTopicTitle($title);

        $slug = Str::uniqueSlug(
            $title,
            fn (string $candidate): bool => $this->findBySlug($boardId, $candidate) !== null,
        );
        $now = gmdate('c');

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO topics (board_id, user_id, title, slug, created_at, last_post_at)
             VALUES (:board_id, :user_id, :title, :slug, :created_at, :last_post_at)'
        );
        $stmt->execute([
            'board_id' => $boardId,
            'user_id' => $userId,
            'title' => $title,
            'slug' => $slug,
            'created_at' => $now,
            'last_post_at' => $lastPostAt,
        ]);

        return $this->findById((int) $this->db->pdo()->lastInsertId()) ?? [];
    }

    /**
     * @return list<int>
     */
    public function activeIdsByBoard(int $boardId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM topics WHERE board_id = :board_id AND deleted_at IS NULL'
        );
        $stmt->execute(['board_id' => $boardId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return list<int>
     */
    public function activePostIds(int $topicId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM posts
             WHERE topic_id = :topic_id AND deleted_at IS NULL
             ORDER BY id ASC'
        );
        $stmt->execute(['topic_id' => $topicId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return array<string, mixed>
     */
    public function createShellTopic(int $boardId, int $userId, string $title, string $lastPostAt): array
    {
        return $this->createShell($boardId, $userId, $title, $lastPostAt);
    }
}
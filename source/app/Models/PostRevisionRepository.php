<?php

declare(strict_types=1);

namespace Latch\Models;

use Latch\Core\Database;

final class PostRevisionRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function save(int $postId, int $editorId, string $body): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO post_revisions (post_id, editor_id, body, created_at)
             VALUES (:post_id, :editor_id, :body, :created_at)'
        );
        $stmt->execute([
            'post_id' => $postId,
            'editor_id' => $editorId,
            'body' => $body,
            'created_at' => gmdate('c'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForPost(int $postId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.id, r.post_id, r.editor_id, r.body, r.created_at, u.username AS editor_username
             FROM post_revisions r
             JOIN users u ON u.id = r.editor_id
             WHERE r.post_id = :post_id
             ORDER BY r.created_at DESC, r.id DESC'
        );
        $stmt->execute(['post_id' => $postId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function countForPost(int $postId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM post_revisions WHERE post_id = :post_id'
        );
        $stmt->execute(['post_id' => $postId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Latest editor per post (for edited badges on topic view).
     *
     * @param list<int> $postIds
     * @return array<int, array{editor_id: int, editor_username: string, edited_at: string}>
     */
    public function latestEditorsForPosts(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter($postIds, static fn (int $id): bool => $id > 0)));
        if ($postIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT post_id, editor_id, editor_username, edited_at FROM (
                SELECT r.post_id, r.editor_id, u.username AS editor_username, r.created_at AS edited_at,
                       ROW_NUMBER() OVER (
                           PARTITION BY r.post_id
                           ORDER BY r.created_at DESC, r.id DESC
                       ) AS row_num
                FROM post_revisions r
                JOIN users u ON u.id = r.editor_id
                WHERE r.post_id IN ({$placeholders})
             ) ranked
             WHERE row_num = 1"
        );
        $stmt->execute($postIds);

        $editors = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $editors[(int) $row['post_id']] = [
                'editor_id' => (int) $row['editor_id'],
                'editor_username' => (string) $row['editor_username'],
                'edited_at' => (string) $row['edited_at'],
            ];
        }

        return $editors;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.id, r.post_id, r.editor_id, r.body, r.created_at, u.username AS editor_username
             FROM post_revisions r
             JOIN users u ON u.id = r.editor_id
             WHERE r.id = :id'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
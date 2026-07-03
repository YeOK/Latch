<?php

declare(strict_types=1);

namespace Latch\Models;

use Latch\Core\Database;
use Latch\Core\TopicTags;
use Latch\Support\Str;

final class TagRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly TopicTags $topicTags,
    ) {
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM tags WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return list<array{id: int, slug: string, name: string}>
     */
    public function forTopic(int $topicId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT tg.id, tg.slug, tg.name
             FROM tags tg
             JOIN topic_tags tt ON tt.tag_id = tg.id
             WHERE tt.topic_id = :topic_id
             ORDER BY tg.name COLLATE NOCASE ASC'
        );
        $stmt->execute(['topic_id' => $topicId]);

        return $stmt->fetchAll();
    }

    /**
     * @param list<int> $topicIds
     * @return array<int, list<array{id: int, slug: string, name: string}>>
     */
    public function forTopics(array $topicIds): array
    {
        if ($topicIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($topicIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT tt.topic_id, tg.id, tg.slug, tg.name
             FROM topic_tags tt
             JOIN tags tg ON tg.id = tt.tag_id
             WHERE tt.topic_id IN ({$placeholders})
             ORDER BY tg.name COLLATE NOCASE ASC"
        );
        $stmt->execute(array_values($topicIds));

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $topicId = (int) $row['topic_id'];
            $map[$topicId][] = [
                'id' => (int) $row['id'],
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
            ];
        }

        return $map;
    }

    /**
     * @param list<string> $names Display names from TopicTags::parse()
     */
    public function syncForTopic(int $topicId, array $names): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM topic_tags WHERE topic_id = :topic_id')->execute(['topic_id' => $topicId]);

        if ($names === []) {
            return;
        }

        $link = $pdo->prepare(
            'INSERT OR IGNORE INTO topic_tags (topic_id, tag_id) VALUES (:topic_id, :tag_id)'
        );

        foreach ($names as $name) {
            $tag = $this->findOrCreate($name);
            $link->execute(['topic_id' => $topicId, 'tag_id' => (int) $tag['id']]);
        }
    }

    /**
     * @return list<array{id: int, slug: string, name: string}>
     */
    public function suggest(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, slug, name FROM tags
             WHERE name LIKE :q COLLATE NOCASE
             ORDER BY name COLLATE NOCASE ASC
             LIMIT :limit'
        );
        $stmt->bindValue('q', '%' . $query . '%');
        $stmt->bindValue('limit', max(1, min(20, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countTopics(string $slug, bool $isMod = false): int
    {
        $approvalWhere = $isMod ? '' : " AND EXISTS (
            SELECT 1 FROM posts ap
            WHERE ap.topic_id = t.id AND ap.deleted_at IS NULL AND ap.approval_status = 'approved'
        )";

        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM topic_tags tt
             JOIN tags tg ON tg.id = tt.tag_id
             JOIN topics t ON t.id = tt.topic_id
             WHERE tg.slug = :slug AND t.deleted_at IS NULL{$approvalWhere}"
        );
        $stmt->execute(['slug' => $slug]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array>
     */
    public function listTopicsByTag(string $slug, int $page, int $perPage, bool $isMod = false): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $approvalWhere = $isMod ? '' : " AND EXISTS (
            SELECT 1 FROM posts ap
            WHERE ap.topic_id = t.id AND ap.deleted_at IS NULL AND ap.approval_status = 'approved'
        )";
        $postCountSql = $isMod
            ? '(SELECT COUNT(*) FROM posts p WHERE p.topic_id = t.id AND p.deleted_at IS NULL)'
            : "(SELECT COUNT(*) FROM posts p WHERE p.topic_id = t.id AND p.deleted_at IS NULL AND p.approval_status = 'approved')";

        $stmt = $this->db->pdo()->prepare(
            "SELECT t.*, u.username AS author_name, u.email AS author_email,
                    b.slug AS board_slug, b.name AS board_name,
                    {$postCountSql} AS post_count
             FROM topic_tags tt
             JOIN tags tg ON tg.id = tt.tag_id
             JOIN topics t ON t.id = tt.topic_id
             JOIN users u ON u.id = t.user_id
             JOIN boards b ON b.id = t.board_id
             WHERE tg.slug = :slug AND t.deleted_at IS NULL{$approvalWhere}
             ORDER BY t.last_post_at DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue('slug', $slug);
        $stmt->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function findOrCreate(string $name): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM tags WHERE name = :name COLLATE NOCASE LIMIT 1');
        $stmt->execute(['name' => $name]);
        $existing = $stmt->fetch();
        if ($existing !== false) {
            return $existing;
        }

        $slug = $this->uniqueSlug($name);
        $now = gmdate('c');
        $insert = $pdo->prepare(
            'INSERT INTO tags (slug, name, created_at) VALUES (:slug, :name, :created_at)'
        );
        $insert->execute([
            'slug' => $slug,
            'name' => $name,
            'created_at' => $now,
        ]);

        $id = (int) $pdo->lastInsertId();
        $row = $this->findById($id);

        return $row ?? ['id' => $id, 'slug' => $slug, 'name' => $name, 'created_at' => $now];
    }

    private function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM tags WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function uniqueSlug(string $name): string
    {
        return Str::uniqueSlug(
            $this->topicTags->slugForName($name),
            fn (string $slug): bool => $this->findBySlug($slug) !== null,
        );
    }
}
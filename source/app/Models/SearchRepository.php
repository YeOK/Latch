<?php

declare(strict_types=1);

namespace Latch\Models;

use Latch\Core\BoardAcl;
use Latch\Core\Database;
use Latch\Core\PostFormatter;
use Latch\Core\SearchExcerpt;
use Latch\Support\Schema;
use PDO;

final class SearchRepository
{
    private ?bool $enabled = null;

    public function __construct(
        private readonly Database $db,
        private readonly PostFormatter $formatter,
        private readonly TagRepository $tags,
    ) {
    }

    private function excludeTrashedSql(string $prefix = ''): string
    {
        if (!Schema::postsHaveTrashQueue($this->db)) {
            return '';
        }

        return ' AND ' . $prefix . 'trashed_at IS NULL';
    }

    public function isEnabled(): bool
    {
        if ($this->enabled !== null) {
            return $this->enabled;
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'search_index' LIMIT 1"
        );
        $stmt->execute();
        $this->enabled = (bool) $stmt->fetchColumn();

        return $this->enabled;
    }

    public function reindexAll(): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        $this->db->pdo()->exec('DELETE FROM search_index');

        $stmt = $this->db->pdo()->query(
            'SELECT id FROM topics WHERE deleted_at IS NULL ORDER BY id ASC'
        );
        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $topicId) {
            $this->indexTopic((int) $topicId);
            $count++;
        }

        return $count;
    }

    public function indexTopic(int $topicId): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM search_index WHERE topic_id = :topic_id')
            ->execute(['topic_id' => $topicId]);

        $topicStmt = $pdo->prepare('SELECT * FROM topics WHERE id = :id LIMIT 1');
        $topicStmt->execute(['id' => $topicId]);
        $topic = $topicStmt->fetch();
        if ($topic === false || $topic['deleted_at'] !== null) {
            return;
        }

        $tagNames = array_map(
            static fn (array $tag): string => (string) $tag['name'],
            $this->tags->forTopic($topicId),
        );
        $tagsText = implode(' ', $tagNames);
        $title = (string) $topic['title'];

        $postsStmt = $pdo->prepare(
            'SELECT id, body, quarantined_at, approval_status FROM posts
             WHERE topic_id = :topic_id AND deleted_at IS NULL' . $this->excludeTrashedSql() . '
             ORDER BY created_at ASC'
        );
        $postsStmt->execute(['topic_id' => $topicId]);

        $insert = $pdo->prepare(
            'INSERT INTO search_index (title, body, tags, topic_id, board_id, post_id)
             VALUES (:title, :body, :tags, :topic_id, :board_id, :post_id)'
        );

        foreach ($postsStmt->fetchAll() as $post) {
            if ($post['quarantined_at'] !== null || ($post['approval_status'] ?? 'approved') !== 'approved') {
                continue;
            }

            $body = htmlspecialchars(
                $this->formatter->plainText((string) $post['body']),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            );
            if ($body === '' && $title === '' && $tagsText === '') {
                continue;
            }

            $insert->execute([
                'title' => $title,
                'body' => $body,
                'tags' => $tagsText,
                'topic_id' => $topicId,
                'board_id' => (int) $topic['board_id'],
                'post_id' => (int) $post['id'],
            ]);
        }
    }

    public function removeTopic(int $topicId): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->db->pdo()->prepare('DELETE FROM search_index WHERE topic_id = :topic_id')
            ->execute(['topic_id' => $topicId]);
    }

    public function removeBoard(int $boardId): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->db->pdo()->prepare('DELETE FROM search_index WHERE board_id = :board_id')
            ->execute(['board_id' => $boardId]);
    }

    /**
     * @return array{results: list<array<string, mixed>>, total: int}
     */
    public function search(string $query, bool $loggedIn, bool $membersOnly, int $page, int $perPage, ?string $userRole = null): array
    {
        if (!$this->isEnabled()) {
            return ['results' => [], 'total' => 0];
        }

        $ftsQuery = $this->buildFtsQuery($query);
        if ($ftsQuery === null) {
            return ['results' => [], 'total' => 0];
        }

        $accessSql = '';
        if ($membersOnly && !$loggedIn) {
            return ['results' => [], 'total' => 0];
        }

        $accessSql = BoardAcl::sqlBoardReadFilter($loggedIn, $userRole);
        $trashSql = $this->excludeTrashedSql('p.');

        $countSql = "SELECT COUNT(DISTINCT si.topic_id)
                     FROM search_index si
                     JOIN topics t ON t.id = si.topic_id AND t.deleted_at IS NULL
                     JOIN boards b ON b.id = si.board_id
                     JOIN posts p ON p.id = si.post_id AND p.deleted_at IS NULL{$trashSql}
                       AND p.quarantined_at IS NULL AND p.approval_status = 'approved'
                     WHERE search_index MATCH :query{$accessSql}";

        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->execute(['query' => $ftsQuery]);
        $total = (int) $countStmt->fetchColumn();

        if ($total === 0) {
            return ['results' => [], 'total' => 0];
        }

        $fetchLimit = min(500, max($perPage * $page * 3, $perPage));
        $sql = "SELECT
                    si.post_id,
                    si.topic_id,
                    si.board_id,
                    t.title,
                    t.last_post_at,
                    b.slug AS board_slug,
                    b.name AS board_name,
                    snippet(search_index, 1, '<mark>', '</mark>', '…', 48) AS excerpt,
                    bm25(search_index) AS score
                FROM search_index si
                JOIN topics t ON t.id = si.topic_id AND t.deleted_at IS NULL
                JOIN boards b ON b.id = si.board_id
                JOIN posts p ON p.id = si.post_id AND p.deleted_at IS NULL{$trashSql}
                  AND p.quarantined_at IS NULL AND p.approval_status = 'approved'
                WHERE search_index MATCH :query{$accessSql}
                ORDER BY score ASC, t.last_post_at DESC
                LIMIT :limit";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('query', $ftsQuery);
        $stmt->bindValue('limit', $fetchLimit, PDO::PARAM_INT);
        $stmt->execute();

        $seenTopics = [];
        $results = [];
        foreach ($stmt->fetchAll() as $row) {
            $topicId = (int) $row['topic_id'];
            if (isset($seenTopics[$topicId])) {
                continue;
            }

            $seenTopics[$topicId] = true;
            $results[] = [
                'post_id' => (int) $row['post_id'],
                'topic_id' => $topicId,
                'board_id' => (int) $row['board_id'],
                'title' => (string) $row['title'],
                'last_post_at' => (string) $row['last_post_at'],
                'board_slug' => (string) $row['board_slug'],
                'board_name' => (string) $row['board_name'],
                'excerpt' => SearchExcerpt::sanitize((string) $row['excerpt']),
            ];

            if (count($results) >= $perPage * $page) {
                break;
            }
        }

        $offset = max(0, ($page - 1) * $perPage);

        return [
            'results' => array_slice($results, $offset, $perPage),
            'total' => $total,
        ];
    }

    private function buildFtsQuery(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $words = preg_split('/\s+/u', $raw) ?: [];
        $terms = [];

        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '') {
                continue;
            }

            $word = preg_replace('/[^\p{L}\p{N}\-]+/u', '', $word) ?? '';
            if ($word === '' || mb_strlen($word) < 2) {
                continue;
            }

            $escaped = str_replace('"', '""', $word);
            $terms[] = '"' . $escaped . '"*';
        }

        if ($terms === []) {
            return null;
        }

        return implode(' AND ', $terms);
    }
}
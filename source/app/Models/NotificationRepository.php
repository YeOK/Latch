<?php

declare(strict_types=1);

namespace Latch\Models;

use Latch\Core\Database;

final class NotificationRepository
{
    public const TYPE_TOPIC_REPLY = 'topic_reply';
    public const TYPE_POST_QUOTE = 'post_quote';
    public const TYPE_POST_LIKE = 'post_like';
    public const TYPE_MENTION = 'mention';
    public const TYPE_STAFF_ACTION = 'staff_action';
    public const TYPE_POST_PENDING = 'post_pending';
    public const TYPE_USER_WARN = 'user_warn';
    public const TYPE_DIRECT_MESSAGE = 'direct_message';

    public function __construct(private readonly Database $db)
    {
    }

    public function create(
        int $userId,
        string $eventType,
        string $message,
        string $url,
        ?int $actorId = null,
        ?int $topicId = null,
        ?int $postId = null,
        ?array $meta = null,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO user_notifications (
                user_id, event_type, message, url, actor_id, topic_id, post_id, meta_json, created_at
             ) VALUES (
                :user_id, :event_type, :message, :url, :actor_id, :topic_id, :post_id, :meta_json, :created_at
             )'
        );
        $stmt->execute([
            'user_id' => $userId,
            'event_type' => $eventType,
            'message' => $message,
            'url' => $url,
            'actor_id' => $actorId,
            'topic_id' => $topicId,
            'post_id' => $postId,
            'meta_json' => $meta !== null ? json_encode($meta, JSON_THROW_ON_ERROR) : null,
            'created_at' => gmdate('c'),
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function findByIdForUser(int $id, int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT n.*, u.username AS actor_username
             FROM user_notifications n
             LEFT JOIN users u ON u.id = n.actor_id
             WHERE n.id = :id AND n.user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function countUnread(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM user_notifications WHERE user_id = :user_id AND read_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT n.*, u.username AS actor_username
             FROM user_notifications n
             LEFT JOIN users u ON u.id = n.actor_id
             WHERE n.user_id = :user_id
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', max(1, min(100, $limit)), \PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->hydrate($row), $stmt->fetchAll());
    }

    public function markRead(int $id, int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE user_notifications
             SET read_at = :read_at
             WHERE id = :id AND user_id = :user_id AND read_at IS NULL'
        );
        $stmt->execute([
            'read_at' => gmdate('c'),
            'id' => $id,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markAllRead(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE user_notifications
             SET read_at = :read_at
             WHERE user_id = :user_id AND read_at IS NULL'
        );
        $stmt->execute([
            'read_at' => gmdate('c'),
            'user_id' => $userId,
        ]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        if (!empty($row['meta_json']) && is_string($row['meta_json'])) {
            $decoded = json_decode($row['meta_json'], true);
            $row['meta'] = is_array($decoded) ? $decoded : [];
        } else {
            $row['meta'] = [];
        }

        $row['is_unread'] = $row['read_at'] === null;

        return $row;
    }
}
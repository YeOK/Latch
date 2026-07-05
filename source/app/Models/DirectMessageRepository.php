<?php

declare(strict_types=1);

namespace Latch\Models;

use Latch\Core\Database;

final class DirectMessageRepository
{
    public const KIND_USER = 'user';
    public const KIND_STAFF_WARNING = 'staff_warning';

    public function __construct(private readonly Database $db)
    {
    }

    public function isAvailable(): bool
    {
        static $cache = [];

        $cacheKey = spl_object_id($this->db->pdo());
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'dm_messages' LIMIT 1"
        );
        $stmt->execute();
        $cache[$cacheKey] = (bool) $stmt->fetchColumn();

        return $cache[$cacheKey];
    }

    public function findOrCreateConversation(int $userA, int $userB): int
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Direct messages are not installed. Run bin/latch migrate.');
        }
        $low = min($userA, $userB);
        $high = max($userA, $userB);
        if ($low <= 0 || $high <= 0 || $low === $high) {
            throw new \InvalidArgumentException('Invalid conversation participants.');
        }

        $existing = $this->findConversationId($userA, $userB);
        if ($existing !== null) {
            return $existing;
        }

        $now = gmdate('c');
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO dm_conversations (user_low, user_high, created_at, updated_at)
                 VALUES (:user_low, :user_high, :created_at, :updated_at)'
            );
            $stmt->execute([
                'user_low' => $low,
                'user_high' => $high,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $conversationId = (int) $pdo->lastInsertId();

            $participantStmt = $pdo->prepare(
                'INSERT INTO dm_participants (conversation_id, user_id, joined_at)
                 VALUES (:conversation_id, :user_id, :joined_at)'
            );
            foreach ([$low, $high] as $userId) {
                $participantStmt->execute([
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'joined_at' => $now,
                ]);
            }

            $pdo->commit();

            return $conversationId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $retry = $this->findConversationId($userA, $userB);
            if ($retry !== null) {
                return $retry;
            }

            throw $e;
        }
    }

    public function findConversationId(int $userA, int $userB): ?int
    {
        $low = min($userA, $userB);
        $high = max($userA, $userB);
        if ($low <= 0 || $high <= 0 || $low === $high) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM dm_conversations WHERE user_low = :user_low AND user_high = :user_high LIMIT 1'
        );
        $stmt->execute(['user_low' => $low, 'user_high' => $high]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    public function isParticipant(int $conversationId, int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM dm_participants
             WHERE conversation_id = :conversation_id AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function hasStaffMessage(int $conversationId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM dm_messages
             WHERE conversation_id = :conversation_id
               AND kind = :kind
               AND deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([
            'conversation_id' => $conversationId,
            'kind' => self::KIND_STAFF_WARNING,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function countActiveMessages(int $conversationId): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM dm_messages
             WHERE conversation_id = :conversation_id AND deleted_at IS NULL'
        );
        $stmt->execute(['conversation_id' => $conversationId]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteConversationIfEmpty(int $conversationId, int $userId): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        if (!$this->isParticipant($conversationId, $userId)) {
            return false;
        }
        if ($this->countActiveMessages($conversationId) > 0) {
            return false;
        }

        $delete = $this->db->pdo()->prepare('DELETE FROM dm_conversations WHERE id = :id');
        $delete->execute(['id' => $conversationId]);

        return $delete->rowCount() > 0;
    }

    public function softDeleteMessage(int $messageId, int $userId, bool $isStaff = false): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT m.id, m.sender_id, m.kind, m.conversation_id
             FROM dm_messages m
             INNER JOIN dm_participants p ON p.conversation_id = m.conversation_id AND p.user_id = :user_id
             WHERE m.id = :message_id AND m.deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(['message_id' => $messageId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }

        $senderId = (int) $row['sender_id'];
        $kind = (string) $row['kind'];
        if (!$isStaff && ($senderId !== $userId || $kind === self::KIND_STAFF_WARNING)) {
            return false;
        }

        $delete = $this->db->pdo()->prepare(
            'UPDATE dm_messages SET deleted_at = :deleted_at WHERE id = :id AND deleted_at IS NULL'
        );
        $delete->execute([
            'deleted_at' => gmdate('c'),
            'id' => $messageId,
        ]);

        return $delete->rowCount() > 0;
    }

    public function addMessage(int $conversationId, int $senderId, string $body, string $kind = self::KIND_USER): int
    {
        $now = gmdate('c');
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO dm_messages (conversation_id, sender_id, body, kind, created_at)
                 VALUES (:conversation_id, :sender_id, :body, :kind, :created_at)'
            );
            $stmt->execute([
                'conversation_id' => $conversationId,
                'sender_id' => $senderId,
                'body' => $body,
                'kind' => $kind,
                'created_at' => $now,
            ]);
            $messageId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'UPDATE dm_conversations SET updated_at = :updated_at WHERE id = :id'
            )->execute([
                'updated_at' => $now,
                'id' => $conversationId,
            ]);

            $pdo->commit();

            return $messageId;
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }

    public function countRecentSends(int $senderId, int $windowMinutes): int
    {
        $since = gmdate('c', time() - ($windowMinutes * 60));
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM dm_messages
             WHERE sender_id = :sender_id AND created_at >= :since AND deleted_at IS NULL'
        );
        $stmt->execute([
            'sender_id' => $senderId,
            'since' => $since,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function countUnreadForUser(int $userId): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
             FROM dm_messages m
             INNER JOIN dm_participants p ON p.conversation_id = m.conversation_id AND p.user_id = :user_id
             WHERE m.sender_id != :user_id
               AND m.deleted_at IS NULL
               AND (p.last_read_message_id IS NULL OR m.id > p.last_read_message_id)'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listConversationsForUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT c.id, c.updated_at,
                    p.last_read_message_id,
                    other.id AS other_user_id,
                    other.username AS other_username,
                    other.role AS other_role,
                    lm.id AS last_message_id,
                    lm.body AS last_message_body,
                    lm.kind AS last_message_kind,
                    lm.sender_id AS last_message_sender_id,
                    lm.created_at AS last_message_at,
                    sender.username AS last_message_sender_username
             FROM dm_conversations c
             INNER JOIN dm_participants p ON p.conversation_id = c.id AND p.user_id = :user_id
             INNER JOIN dm_participants p_other ON p_other.conversation_id = c.id AND p_other.user_id != :user_id
             INNER JOIN users other ON other.id = p_other.user_id
             LEFT JOIN dm_messages lm ON lm.id = (
                 SELECT m2.id FROM dm_messages m2
                 WHERE m2.conversation_id = c.id AND m2.deleted_at IS NULL
                 ORDER BY m2.created_at DESC, m2.id DESC
                 LIMIT 1
             )
             LEFT JOIN users sender ON sender.id = lm.sender_id
             ORDER BY c.updated_at DESC, c.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', max(1, min(100, $limit)), \PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        return array_map(fn (array $row): array => $this->hydrateConversation($row, $userId), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMessages(
        int $conversationId,
        int $userId,
        int $limit = 50,
        ?int $afterId = null,
    ): array {
        if (!$this->isParticipant($conversationId, $userId)) {
            return [];
        }

        $sql = 'SELECT m.*, u.username AS sender_username, u.role AS sender_role
                FROM dm_messages m
                LEFT JOIN users u ON u.id = m.sender_id
                WHERE m.conversation_id = :conversation_id AND m.deleted_at IS NULL';
        $params = ['conversation_id' => $conversationId];

        if ($afterId !== null && $afterId > 0) {
            $sql .= ' AND m.id > :after_id';
            $params['after_id'] = $afterId;
        } else {
            $sql .= ' ORDER BY m.created_at DESC, m.id DESC LIMIT :limit';
        }

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, \PDO::PARAM_INT);
        }

        if ($afterId === null || $afterId <= 0) {
            $stmt->bindValue('limit', max(1, min(100, $limit)), \PDO::PARAM_INT);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll();

        if ($afterId === null || $afterId <= 0) {
            $rows = array_reverse($rows);
        }

        return array_map(fn (array $row): array => $this->hydrateMessage($row, $userId), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getConversationForUser(int $conversationId, int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.id, c.updated_at,
                    p.last_read_message_id,
                    other.id AS other_user_id,
                    other.username AS other_username,
                    other.role AS other_role
             FROM dm_conversations c
             INNER JOIN dm_participants p ON p.conversation_id = c.id AND p.user_id = :user_id
             INNER JOIN dm_participants p_other ON p_other.conversation_id = c.id AND p_other.user_id != :user_id
             INNER JOIN users other ON other.id = p_other.user_id
             WHERE c.id = :conversation_id
             LIMIT 1'
        );
        $stmt->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
        ]);
        $row = $stmt->fetch();

        return $row ? $this->hydrateConversation($row, $userId) : null;
    }

    public function markRead(int $conversationId, int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MAX(id) FROM dm_messages
             WHERE conversation_id = :conversation_id AND deleted_at IS NULL'
        );
        $stmt->execute(['conversation_id' => $conversationId]);
        $lastId = $stmt->fetchColumn();
        if ($lastId === false) {
            return;
        }

        $update = $this->db->pdo()->prepare(
            'UPDATE dm_participants
             SET last_read_message_id = :last_read_message_id, last_read_at = :last_read_at
             WHERE conversation_id = :conversation_id AND user_id = :user_id'
        );
        $update->execute([
            'last_read_message_id' => (int) $lastId,
            'last_read_at' => gmdate('c'),
            'conversation_id' => $conversationId,
            'user_id' => $userId,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateConversation(array $row, int $viewerId): array
    {
        $lastMessageId = isset($row['last_message_id']) ? (int) $row['last_message_id'] : 0;
        $lastReadId = isset($row['last_read_message_id']) ? (int) $row['last_read_message_id'] : 0;
        $lastSenderId = isset($row['last_message_sender_id']) ? (int) $row['last_message_sender_id'] : 0;
        $unread = $lastMessageId > 0
            && $lastSenderId !== $viewerId
            && ($lastReadId === 0 || $lastMessageId > $lastReadId);

        $preview = '';
        if (!empty($row['last_message_body'])) {
            $preview = $this->previewBody((string) $row['last_message_body']);
        }

        $otherRole = (string) ($row['other_role'] ?? 'member');

        return [
            'id' => (int) $row['id'],
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'other_user' => [
                'id' => (int) ($row['other_user_id'] ?? 0),
                'username' => (string) ($row['other_username'] ?? ''),
                'is_staff' => in_array($otherRole, ['admin', 'mod'], true),
            ],
            'last_message' => $lastMessageId > 0 ? [
                'id' => $lastMessageId,
                'preview' => $preview,
                'kind' => (string) ($row['last_message_kind'] ?? self::KIND_USER),
                'sender_id' => $lastSenderId,
                'sender_username' => (string) ($row['last_message_sender_username'] ?? ''),
                'created_at' => (string) ($row['last_message_at'] ?? ''),
                'is_mine' => $lastSenderId === $viewerId,
            ] : null,
            'unread' => $unread,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateMessage(array $row, int $viewerId): array
    {
        $senderId = (int) ($row['sender_id'] ?? 0);
        $role = (string) ($row['sender_role'] ?? 'member');

        return [
            'id' => (int) $row['id'],
            'body' => (string) ($row['body'] ?? ''),
            'kind' => (string) ($row['kind'] ?? self::KIND_USER),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'sender' => [
                'id' => $senderId,
                'username' => (string) ($row['sender_username'] ?? ''),
                'is_staff' => in_array($role, ['admin', 'mod'], true),
            ],
            'is_mine' => $senderId === $viewerId,
        ];
    }

    private function previewBody(string $body): string
    {
        $plain = trim(preg_replace('/\s+/', ' ', $body) ?? $body);
        if (mb_strlen($plain) <= 120) {
            return $plain;
        }

        return mb_substr($plain, 0, 119) . '…';
    }
}
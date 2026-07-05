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
use Latch\Core\InputValidator;
use Latch\Support\Str;

final class BoardRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly ?InputValidator $input = null,
    ) {
    }

    public function all(): array
    {
        return $this->db->pdo()->query(
            'SELECT * FROM boards ORDER BY sort_order ASC, name ASC'
        )->fetchAll();
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM boards WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM boards WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array{read: string, topic: string, reply: string}
     */
    public function normalizeAclInput(string $aclRead, string $aclTopic, string $aclReply): array
    {
        return [
            'read' => BoardAcl::normalize(BoardAcl::ACTION_READ, $aclRead),
            'topic' => BoardAcl::normalize(BoardAcl::ACTION_TOPIC, $aclTopic),
            'reply' => BoardAcl::normalize(BoardAcl::ACTION_REPLY, $aclReply),
        ];
    }

    public function create(
        string $name,
        string $description = '',
        string $aclRead = BoardAcl::ROLE_GUEST,
        string $aclTopic = BoardAcl::ROLE_MEMBER,
        string $aclReply = BoardAcl::ROLE_MEMBER,
        ?string $slug = null,
    ): array {
        $this->input?->assertBoardName($name);
        $this->input?->assertBoardDescription($description);

        $acl = $this->normalizeAclInput($aclRead, $aclTopic, $aclReply);
        if ($slug !== null && $slug !== '') {
            $slug = Str::slug($slug);
            if ($this->findBySlug($slug) !== null) {
                $slug = Str::uniqueSlug($slug, fn (string $s): bool => $this->findBySlug($s) !== null);
            }
        } else {
            $slug = Str::uniqueSlug($name, fn (string $s): bool => $this->findBySlug($s) !== null);
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO boards (
                slug, name, description, sort_order,
                requires_login_to_read, staff_only_topics,
                acl_read, acl_topic, acl_reply
             )
             VALUES (
                :slug, :name, :description, :sort_order,
                :requires_login, :staff_only_topics,
                :acl_read, :acl_topic, :acl_reply
             )'
        );
        $stmt->execute([
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'sort_order' => (int) $this->db->pdo()->query('SELECT COUNT(*) FROM boards')->fetchColumn(),
            'requires_login' => BoardAcl::isMembersOnlyRead(['acl_read' => $acl['read']]) ? 1 : 0,
            'staff_only_topics' => BoardAcl::isStaffOnlyTopics(['acl_topic' => $acl['topic']]) ? 1 : 0,
            'acl_read' => $acl['read'],
            'acl_topic' => $acl['topic'],
            'acl_reply' => $acl['reply'],
        ]);

        $board = $this->findById((int) $this->db->pdo()->lastInsertId()) ?? [];

        return $board;
    }

    public function setIconKey(int $id, string $iconKey): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE boards SET icon_key = :icon_key WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'icon_key' => $iconKey,
        ]);
    }

    public function isSlugTaken(string $slug, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT 1 FROM boards WHERE slug = :slug AND id != :id LIMIT 1'
            );
            $stmt->execute(['slug' => $slug, 'id' => $excludeId]);
        } else {
            $stmt = $this->db->pdo()->prepare('SELECT 1 FROM boards WHERE slug = :slug LIMIT 1');
            $stmt->execute(['slug' => $slug]);
        }

        return (bool) $stmt->fetchColumn();
    }

    public function update(
        int $id,
        string $name,
        string $slug,
        string $description,
        string $aclRead = BoardAcl::ROLE_GUEST,
        string $aclTopic = BoardAcl::ROLE_MEMBER,
        string $aclReply = BoardAcl::ROLE_MEMBER,
    ): bool {
        $this->input?->assertBoardName($name);
        $this->input?->assertBoardDescription($description);

        $acl = $this->normalizeAclInput($aclRead, $aclTopic, $aclReply);

        $stmt = $this->db->pdo()->prepare(
            'UPDATE boards
             SET slug = :slug, name = :name, description = :description,
                 requires_login_to_read = :requires_login, staff_only_topics = :staff_only_topics,
                 acl_read = :acl_read, acl_topic = :acl_topic, acl_reply = :acl_reply
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'requires_login' => BoardAcl::isMembersOnlyRead(['acl_read' => $acl['read']]) ? 1 : 0,
            'staff_only_topics' => BoardAcl::isStaffOnlyTopics(['acl_topic' => $acl['topic']]) ? 1 : 0,
            'acl_read' => $acl['read'],
            'acl_topic' => $acl['topic'],
            'acl_reply' => $acl['reply'],
        ]);

        return $stmt->rowCount() > 0;
    }

    /** Persist optional per-board minimum member rank (1–5) for read/topic/reply; NULL clears a gate. */
    public function setMinRanks(int $id, ?int $read, ?int $topic, ?int $reply): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE boards
             SET min_rank_read = :min_rank_read,
                 min_rank_topic = :min_rank_topic,
                 min_rank_reply = :min_rank_reply
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'min_rank_read' => $read,
            'min_rank_topic' => $topic,
            'min_rank_reply' => $reply,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM boards WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function count(): int
    {
        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM boards')->fetchColumn();
    }

    public function countTopics(int $boardId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM topics WHERE board_id = :board_id AND deleted_at IS NULL'
        );
        $stmt->execute(['board_id' => $boardId]);

        return (int) $stmt->fetchColumn();
    }

    public function move(int $id, string $direction): bool
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            return false;
        }

        $boards = $this->all();
        $index = null;
        foreach ($boards as $i => $board) {
            if ((int) $board['id'] === $id) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return false;
        }

        $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swapIndex < 0 || $swapIndex >= count($boards)) {
            return false;
        }

        $current = $boards[$index];
        $neighbor = $boards[$swapIndex];
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('UPDATE boards SET sort_order = :sort_order WHERE id = :id');
            $stmt->execute([
                'id' => (int) $current['id'],
                'sort_order' => (int) $neighbor['sort_order'],
            ]);
            $stmt->execute([
                'id' => (int) $neighbor['id'],
                'sort_order' => (int) $current['sort_order'],
            ]);
            $pdo->commit();
        } catch (\Throwable) {
            $pdo->rollBack();

            return false;
        }

        return true;
    }

    public function canRead(
        array $board,
        bool $loggedIn,
        bool $membersOnly,
        ?string $userRole = null,
        ?int $reputationRank = null,
    ): bool {
        return BoardAcl::allows($board, BoardAcl::ACTION_READ, $loggedIn, $userRole, $membersOnly, $reputationRank);
    }

    public function canCreateTopic(
        array $board,
        bool $loggedIn,
        ?string $userRole,
        bool $membersOnly,
        ?int $reputationRank = null,
    ): bool {
        if (!$loggedIn) {
            return false;
        }

        return BoardAcl::allows($board, BoardAcl::ACTION_TOPIC, true, $userRole, $membersOnly, $reputationRank);
    }

    public function canReply(
        array $board,
        bool $loggedIn,
        ?string $userRole,
        bool $membersOnly,
        ?int $reputationRank = null,
    ): bool {
        return BoardAcl::allows($board, BoardAcl::ACTION_REPLY, $loggedIn, $userRole, $membersOnly, $reputationRank);
    }

    public function sqlReadFilter(bool $loggedIn, ?string $userRole): string
    {
        return BoardAcl::sqlBoardReadFilter($loggedIn, $userRole);
    }
}
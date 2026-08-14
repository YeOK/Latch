<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Models;

use Latch\Core\Database;

/**
 * Member follows. Self-follow is rejected; deleted/banned targets are skipped.
 */
final class UserFollowRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
    ) {
    }

    public function isFollowing(int $followerId, int $followingId): bool
    {
        if ($followerId <= 0 || $followingId <= 0 || $followerId === $followingId) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM user_follows WHERE follower_id = :follower AND following_id = :following LIMIT 1'
        );
        $stmt->execute(['follower' => $followerId, 'following' => $followingId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return array{ok: bool, following: bool, error?: string}
     */
    public function follow(int $followerId, int $followingId): array
    {
        if ($followerId <= 0 || $followingId <= 0) {
            return ['ok' => false, 'following' => false, 'error' => 'Invalid user.'];
        }

        if ($followerId === $followingId) {
            return ['ok' => false, 'following' => false, 'error' => 'You cannot follow yourself.'];
        }

        $target = $this->users->findById($followingId);
        if ($target === null || $this->users->isDeleted($target) || $this->users->isAnonymised($target)) {
            return ['ok' => false, 'following' => false, 'error' => 'User not found.'];
        }

        if ($this->users->isBanned($target)) {
            return ['ok' => false, 'following' => false, 'error' => 'You cannot follow this member.'];
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT OR IGNORE INTO user_follows (follower_id, following_id, created_at)
             VALUES (:follower, :following, :created_at)'
        );
        $stmt->execute([
            'follower' => $followerId,
            'following' => $followingId,
            'created_at' => gmdate('c'),
        ]);

        return ['ok' => true, 'following' => true];
    }

    /**
     * @return array{ok: bool, following: bool}
     */
    public function unfollow(int $followerId, int $followingId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM user_follows WHERE follower_id = :follower AND following_id = :following'
        );
        $stmt->execute(['follower' => $followerId, 'following' => $followingId]);

        return ['ok' => true, 'following' => false];
    }

    /**
     * @return array{ok: bool, following: bool, error?: string}
     */
    public function toggle(int $followerId, int $followingId): array
    {
        if ($this->isFollowing($followerId, $followingId)) {
            return $this->unfollow($followerId, $followingId);
        }

        return $this->follow($followerId, $followingId);
    }

    public function followerCount(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM user_follows WHERE following_id = :id'
        );
        $stmt->execute(['id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function followingCount(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM user_follows WHERE follower_id = :id'
        );
        $stmt->execute(['id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * User ids that follow $userId (they should be notified when $userId posts a topic).
     *
     * @return list<int>
     */
    public function followerIds(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT follower_id FROM user_follows WHERE following_id = :id'
        );
        $stmt->execute(['id' => $userId]);

        return array_map(static fn ($id): int => (int) $id, $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFollowing(int $followerId, int $limit = 30, int $offset = 0): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT u.id, u.username, u.email, u.role, u.created_at AS user_created_at,
                    f.created_at AS followed_at
             FROM user_follows f
             INNER JOIN users u ON u.id = f.following_id
             WHERE f.follower_id = :id
               AND u.deleted_at IS NULL
             ORDER BY f.created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('id', $followerId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }
}

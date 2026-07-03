<?php

declare(strict_types=1);

namespace Latch\Models;

use Latch\Core\Database;
use RuntimeException;

final class PostReactionRepository
{
    public const VOTE_LIKE = 'like';
    public const VOTE_DISLIKE = 'dislike';

    public function __construct(private readonly Database $db)
    {
    }

    public function findVote(int $postId, int $userId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT vote FROM post_reactions WHERE post_id = :post_id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['post_id' => $postId, 'user_id' => $userId]);
        $vote = $stmt->fetchColumn();

        return is_string($vote) ? $vote : null;
    }

    /**
     * @return array{like_count: int, dislike_count: int, viewer_vote: ?string}
     */
    public function countsForPost(int $postId, ?int $viewerUserId = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT like_count, dislike_count FROM posts WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $postId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException('Post not found.');
        }

        $viewerVote = null;
        if ($viewerUserId !== null) {
            $viewerVote = $this->findVote($postId, $viewerUserId);
        }

        return [
            'like_count' => (int) ($row['like_count'] ?? 0),
            'dislike_count' => (int) ($row['dislike_count'] ?? 0),
            'viewer_vote' => $viewerVote,
        ];
    }

    /**
     * @return array{
     *     like_count: int,
     *     dislike_count: int,
     *     viewer_vote: ?string,
     *     became_like: bool
     * }
     */
    public function setVote(int $postId, int $userId, ?string $vote): array
    {
        if ($vote !== null && !in_array($vote, [self::VOTE_LIKE, self::VOTE_DISLIKE], true)) {
            throw new RuntimeException('Invalid vote.');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $postStmt = $pdo->prepare(
                'SELECT id, user_id, deleted_at, quarantined_at, approval_status, like_count, dislike_count
                 FROM posts WHERE id = :id LIMIT 1'
            );
            $postStmt->execute(['id' => $postId]);
            $post = $postStmt->fetch();
            if ($post === false || $post['deleted_at'] !== null) {
                throw new RuntimeException('Post not found.');
            }

            if ((int) $post['user_id'] === $userId) {
                throw new RuntimeException('You cannot vote on your own post.');
            }

            if (($post['approval_status'] ?? 'approved') !== PostRepository::APPROVAL_APPROVED) {
                throw new RuntimeException('This post cannot be voted on yet.');
            }

            if ($post['quarantined_at'] !== null) {
                throw new RuntimeException('This post cannot be voted on.');
            }

            $existingStmt = $pdo->prepare(
                'SELECT vote FROM post_reactions WHERE post_id = :post_id AND user_id = :user_id LIMIT 1'
            );
            $existingStmt->execute(['post_id' => $postId, 'user_id' => $userId]);
            $existing = $existingStmt->fetchColumn();
            $existingVote = is_string($existing) ? $existing : null;

            $likeDelta = 0;
            $dislikeDelta = 0;
            $becameLike = false;
            $now = gmdate('c');

            if ($vote === null) {
                if ($existingVote === self::VOTE_LIKE) {
                    $likeDelta = -1;
                } elseif ($existingVote === self::VOTE_DISLIKE) {
                    $dislikeDelta = -1;
                }
                if ($existingVote !== null) {
                    $delete = $pdo->prepare(
                        'DELETE FROM post_reactions WHERE post_id = :post_id AND user_id = :user_id'
                    );
                    $delete->execute(['post_id' => $postId, 'user_id' => $userId]);
                }
            } elseif ($existingVote === null) {
                $insert = $pdo->prepare(
                    'INSERT INTO post_reactions (post_id, user_id, vote, created_at, updated_at)
                     VALUES (:post_id, :user_id, :vote, :created_at, :updated_at)'
                );
                $insert->execute([
                    'post_id' => $postId,
                    'user_id' => $userId,
                    'vote' => $vote,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($vote === self::VOTE_LIKE) {
                    $likeDelta = 1;
                    $becameLike = true;
                } else {
                    $dislikeDelta = 1;
                }
            } elseif ($existingVote === $vote) {
                if ($vote === self::VOTE_LIKE) {
                    $likeDelta = -1;
                } else {
                    $dislikeDelta = -1;
                }
                $delete = $pdo->prepare(
                    'DELETE FROM post_reactions WHERE post_id = :post_id AND user_id = :user_id'
                );
                $delete->execute(['post_id' => $postId, 'user_id' => $userId]);
                $vote = null;
            } else {
                $update = $pdo->prepare(
                    'UPDATE post_reactions SET vote = :vote, updated_at = :updated_at
                     WHERE post_id = :post_id AND user_id = :user_id'
                );
                $update->execute([
                    'vote' => $vote,
                    'updated_at' => $now,
                    'post_id' => $postId,
                    'user_id' => $userId,
                ]);
                if ($vote === self::VOTE_LIKE) {
                    $likeDelta = 1;
                    $dislikeDelta = -1;
                    $becameLike = true;
                } else {
                    $likeDelta = -1;
                    $dislikeDelta = 1;
                }
            }

            if ($likeDelta !== 0 || $dislikeDelta !== 0) {
                $counts = $pdo->prepare(
                    'UPDATE posts
                     SET like_count = MAX(0, like_count + :like_delta),
                         dislike_count = MAX(0, dislike_count + :dislike_delta)
                     WHERE id = :id'
                );
                $counts->execute([
                    'like_delta' => $likeDelta,
                    'dislike_delta' => $dislikeDelta,
                    'id' => $postId,
                ]);
            }

            $pdo->commit();

            $fresh = $this->countsForPost($postId, $userId);

            return [
                'like_count' => $fresh['like_count'],
                'dislike_count' => $fresh['dislike_count'],
                'viewer_vote' => $fresh['viewer_vote'],
                'became_like' => $becameLike,
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function countRecentChanges(int $userId, int $windowMinutes): int
    {
        $since = gmdate('c', time() - ($windowMinutes * 60));
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM post_reactions
             WHERE user_id = :user_id AND updated_at >= :since'
        );
        $stmt->execute(['user_id' => $userId, 'since' => $since]);

        return (int) $stmt->fetchColumn();
    }
}
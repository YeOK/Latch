<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use Latch\Core\Auth;
use Latch\Models\DirectMessageRepository;

/**
 * Maps internal rows to public API field sets.
 */
final class ApiSerializer
{
    /**
     * @param array<string, mixed> $board
     * @return array<string, mixed>
     */
    public function board(array $board): array
    {
        return [
            'id' => (int) $board['id'],
            'slug' => (string) $board['slug'],
            'name' => (string) $board['name'],
            'description' => (string) ($board['description'] ?? ''),
            'sort_order' => (int) ($board['sort_order'] ?? 0),
            'icon_key' => (string) ($board['icon_key'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $topic
     * @return array<string, mixed>
     */
    public function topic(array $topic): array
    {
        return [
            'id' => (int) $topic['id'],
            'board_id' => (int) $topic['board_id'],
            'title' => (string) $topic['title'],
            'slug' => (string) ($topic['slug'] ?? ''),
            'author' => (string) ($topic['author_name'] ?? ''),
            'is_locked' => !empty($topic['is_locked']),
            'is_pinned' => !empty($topic['is_pinned']),
            'post_count' => (int) ($topic['post_count'] ?? 0),
            'created_at' => (string) ($topic['created_at'] ?? ''),
            'last_post_at' => (string) ($topic['last_post_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @param bool $includeBody
     */
    public function post(array $post, bool $includeBody = true): array
    {
        $row = [
            'id' => (int) $post['id'],
            'topic_id' => (int) $post['topic_id'],
            'author' => (string) ($post['author_name'] ?? ''),
            'created_at' => (string) ($post['created_at'] ?? ''),
            'updated_at' => (string) ($post['updated_at'] ?? ''),
            'like_count' => (int) ($post['like_count'] ?? 0),
            'dislike_count' => (int) ($post['dislike_count'] ?? 0),
            'is_edited' => ($post['updated_at'] ?? null) !== null
                && ($post['updated_at'] ?? '') !== ($post['created_at'] ?? ''),
        ];

        if ($includeBody) {
            $row['body'] = (string) ($post['body'] ?? '');
        } else {
            $row['body'] = null;
            $row['quarantined'] = true;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function conversation(array $item): array
    {
        $last = $item['last_message'] ?? null;

        return [
            'id' => (int) $item['id'],
            'updated_at' => (string) ($item['updated_at'] ?? ''),
            'unread' => !empty($item['unread']),
            'other_user' => $item['other_user'] ?? [],
            'last_message' => is_array($last) ? [
                'id' => (int) ($last['id'] ?? 0),
                'preview' => (string) ($last['preview'] ?? ''),
                'kind' => (string) ($last['kind'] ?? DirectMessageRepository::KIND_USER),
                'sender_id' => (int) ($last['sender_id'] ?? 0),
                'sender_username' => (string) ($last['sender_username'] ?? ''),
                'created_at' => (string) ($last['created_at'] ?? ''),
            ] : null,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function message(array $item, int $viewerId, PostFormatter $formatter): array
    {
        $kind = (string) ($item['kind'] ?? DirectMessageRepository::KIND_USER);
        $body = (string) ($item['body'] ?? '');
        $senderId = (int) ($item['sender_id'] ?? 0);

        return [
            'id' => (int) $item['id'],
            'body' => $body,
            'body_html' => $formatter->format($body),
            'kind' => $kind,
            'is_warning' => $kind === DirectMessageRepository::KIND_STAFF_WARNING,
            'created_at' => (string) ($item['created_at'] ?? ''),
            'sender' => [
                'id' => $senderId,
                'username' => (string) ($item['sender_username'] ?? ($item['sender']['username'] ?? '')),
                'is_staff' => in_array((string) ($item['sender_role'] ?? ($item['sender']['role'] ?? 'member')), ['admin', 'mod'], true),
            ],
            'is_mine' => $senderId === $viewerId,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array{post_count: int, topic_count: int} $stats
     */
    public function user(array $user, array $stats, string $avatarSrc): array
    {
        $role = (string) ($user['role'] ?? Auth::ROLE_MEMBER);
        $isStaff = in_array($role, [Auth::ROLE_ADMIN, Auth::ROLE_MOD], true);

        $row = [
            'username' => (string) $user['username'],
            'bio' => (string) ($user['bio'] ?? ''),
            'created_at' => (string) ($user['created_at'] ?? ''),
            'avatar_url' => $avatarSrc,
            'post_count' => $stats['post_count'],
            'topic_count' => $stats['topic_count'],
            'is_staff' => $isStaff,
            'staff_label' => match ($role) {
                Auth::ROLE_ADMIN => 'Admin',
                Auth::ROLE_MOD => 'Moderator',
                default => null,
            },
        ];

        if (!$isStaff && isset($user['reputation_rank']) && $user['reputation_rank'] !== null) {
            $row['reputation_rank'] = (int) $user['reputation_rank'];
            $row['reputation_label'] = ReputationService::rankLabel((int) $user['reputation_rank']);
        }

        return $row;
    }
}
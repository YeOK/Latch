<?php

declare(strict_types=1);

namespace Latch\Core;

use Latch\Models\BoardRepository;
use Latch\Models\PostRepository;
use Latch\Models\SearchRepository;
use Latch\Models\SettingRepository;
use Latch\Models\TopicRepository;
use Latch\Support\Schema;

/**
 * Archives removed posts/topics to a staff-only board (one topic per removed post).
 */
final class ModerationTrashService
{
    public const BOARD_SLUG = 'mod-trash';
    public const SETTING_BOARD_ID = 'moderation_trash_board_id';

    public function __construct(
        private readonly Database $db,
        private readonly BoardRepository $boards,
        private readonly TopicRepository $topics,
        private readonly PostRepository $posts,
        private readonly SettingRepository $settings,
        private readonly ?SearchRepository $search = null,
    ) {
    }

    public function trashBoard(): ?array
    {
        $id = (int) ($this->settings->get(self::SETTING_BOARD_ID, '0') ?? '0');
        if ($id > 0) {
            $board = $this->boards->findById($id);
            if ($board !== null) {
                return $board;
            }
        }

        $existing = $this->boards->findBySlug(self::BOARD_SLUG);
        if ($existing !== null) {
            $this->settings->set(self::SETTING_BOARD_ID, (string) $existing['id']);

            return $existing;
        }

        return null;
    }

    public function ensureTrashBoard(): array
    {
        $board = $this->trashBoard();
        if ($board !== null) {
            return $board;
        }

        $board = $this->boards->create(
            'Moderation trash',
            'Removed posts and deleted topics for staff review.',
            BoardAcl::ROLE_MOD,
            BoardAcl::ROLE_ADMIN,
            BoardAcl::ROLE_ADMIN,
            self::BOARD_SLUG,
        );
        $this->settings->set(self::SETTING_BOARD_ID, (string) $board['id']);

        return $board;
    }

    public function archivePost(int $postId, int $staffUserId): ?int
    {
        if (!Schema::postsHaveTrashQueue($this->db)) {
            return null;
        }

        $post = $this->posts->findById($postId);
        if ($post === null || ($post['deleted_at'] ?? null) !== null || ($post['trashed_at'] ?? null) !== null) {
            return null;
        }

        $sourceTopic = $this->topics->findById((int) $post['topic_id']);
        if ($sourceTopic === null || ($sourceTopic['deleted_at'] ?? null) !== null) {
            return null;
        }

        $sourceBoard = $this->boards->findById((int) $sourceTopic['board_id']);
        if ($sourceBoard === null || (string) ($sourceBoard['slug'] ?? '') === self::BOARD_SLUG) {
            return null;
        }

        $trashBoard = $this->ensureTrashBoard();
        $title = $this->archiveTitle((string) $sourceBoard['name'], (string) $sourceTopic['title']);
        $archiveTopic = $this->topics->createShellTopic(
            (int) $trashBoard['id'],
            $staffUserId,
            $title,
            (string) ($post['created_at'] ?? gmdate('c')),
        );

        if (!$this->posts->trash($postId, $staffUserId, (int) $sourceTopic['id'], (int) $sourceBoard['id'])) {
            return null;
        }

        $this->posts->reassignPosts([$postId], (int) $archiveTopic['id']);
        $this->topics->touchLastPost((int) $archiveTopic['id'], (string) ($post['created_at'] ?? null));
        $this->afterSourceTopicChange($sourceTopic);
        $this->indexArchiveTopic((int) $archiveTopic['id']);

        return (int) $archiveTopic['id'];
    }

    public function archiveTopic(int $topicId, int $staffUserId): int
    {
        $sourceTopic = $this->topics->findById($topicId);
        if ($sourceTopic === null || ($sourceTopic['deleted_at'] ?? null) !== null) {
            return 0;
        }

        $sourceBoard = $this->boards->findById((int) $sourceTopic['board_id']);
        if ($sourceBoard === null || (string) ($sourceBoard['slug'] ?? '') === self::BOARD_SLUG) {
            return 0;
        }

        $postIds = $this->topics->activePostIds($topicId);
        $archived = 0;

        foreach ($postIds as $postId) {
            if ($this->archivePost($postId, $staffUserId) !== null) {
                $archived++;
            }
        }

        $sourceTopic = $this->topics->findById($topicId);
        if ($sourceTopic !== null && ($sourceTopic['deleted_at'] ?? null) === null) {
            if ($this->topics->activePostIds($topicId) === []) {
                $this->topics->softDelete($topicId);
                $this->search?->removeTopic($topicId);
            }
        }

        return $archived;
    }

    /**
     * @param array<string, mixed> $board
     */
    public function isTrashBoard(array $board): bool
    {
        $trash = $this->trashBoard();
        if ($trash !== null && (int) $board['id'] === (int) $trash['id']) {
            return true;
        }

        return (string) ($board['slug'] ?? '') === self::BOARD_SLUG;
    }

    public function trashBoardPath(): string
    {
        $board = $this->trashBoard() ?? $this->ensureTrashBoard();

        return '/board/' . (string) ($board['slug'] ?? self::BOARD_SLUG);
    }

    /**
     * @return array<string, int>|null
     */
    public function restoreTrashedPost(int $postId): ?array
    {
        $post = $this->posts->findById($postId);
        if ($post === null || !$this->posts->isTrashed($postId)) {
            return null;
        }

        $archiveTopicId = (int) $post['topic_id'];
        $restoreTopicId = (int) ($post['trash_restore_topic_id'] ?? 0);
        if ($restoreTopicId <= 0 || !$this->posts->restoreFromTrash($postId)) {
            return null;
        }

        $this->cleanupArchiveTopicIfEmpty($archiveTopicId);

        return [
            'post_id' => $postId,
            'archive_topic_id' => $archiveTopicId,
            'restore_topic_id' => $restoreTopicId,
            'restore_board_id' => (int) ($post['trash_restore_board_id'] ?? 0),
            'author_user_id' => (int) $post['user_id'],
        ];
    }

    /**
     * @return array<string, int>|null
     */
    public function purgeTrashedPost(int $postId): ?array
    {
        $post = $this->posts->findById($postId);
        if ($post === null || !$this->posts->isTrashed($postId)) {
            return null;
        }

        $archiveTopicId = (int) $post['topic_id'];
        $restoreTopicId = (int) ($post['trash_restore_topic_id'] ?? 0);
        if (!$this->posts->purgeFromTrash($postId)) {
            return null;
        }

        $this->cleanupArchiveTopicIfEmpty($archiveTopicId);

        return [
            'post_id' => $postId,
            'archive_topic_id' => $archiveTopicId,
            'restore_topic_id' => $restoreTopicId,
            'author_user_id' => (int) $post['user_id'],
        ];
    }

    /**
     * Permanently delete all trashed posts in a mod-trash archive topic (and remove the topic).
     *
     * @return array{archive_topic_id: int, purged: list<array<string, int>>}|null
     */
    public function purgeTrashTopic(int $archiveTopicId): ?array
    {
        $topic = $this->topics->findById($archiveTopicId);
        if ($topic === null || ($topic['deleted_at'] ?? null) !== null) {
            return null;
        }

        $board = $this->boards->findById((int) $topic['board_id']);
        if ($board === null || !$this->isTrashBoard($board)) {
            return null;
        }

        $purged = [];
        foreach ($this->topics->activePostIds($archiveTopicId) as $postId) {
            if (!$this->posts->isTrashed($postId)) {
                continue;
            }

            $result = $this->purgeTrashedPost($postId);
            if ($result !== null) {
                $purged[] = $result;
            }
        }

        if ($purged === []) {
            return null;
        }

        return [
            'archive_topic_id' => $archiveTopicId,
            'purged' => $purged,
        ];
    }

    /**
     * Permanently delete every archived post on the moderation trash board.
     *
     * @return array{
     *     purged_posts: int,
     *     purged_topics: int,
     *     purged: list<array<string, int>>
     * }
     */
    public function purgeAllTrash(): array
    {
        $trashBoard = $this->trashBoard() ?? $this->ensureTrashBoard();
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM topics WHERE board_id = :board_id AND deleted_at IS NULL ORDER BY id ASC'
        );
        $stmt->execute(['board_id' => (int) $trashBoard['id']]);
        $topicIds = array_map(static fn (array $row): int => (int) $row['id'], $stmt->fetchAll());

        $purgedPosts = 0;
        $purgedTopics = 0;
        $purged = [];

        foreach ($topicIds as $topicId) {
            $result = $this->purgeTrashTopic($topicId);
            if ($result === null) {
                continue;
            }

            $purgedTopics++;
            foreach ($result['purged'] as $entry) {
                $purged[] = $entry;
                $purgedPosts++;
            }
        }

        return [
            'purged_posts' => $purgedPosts,
            'purged_topics' => $purgedTopics,
            'purged' => $purged,
        ];
    }

    private function archiveTitle(string $boardName, string $topicTitle): string
    {
        $boardName = trim($boardName);
        $topicTitle = trim($topicTitle);
        $title = 'Removed from ' . $boardName . ' / ' . $topicTitle;

        return mb_strlen($title) > 255 ? mb_substr($title, 0, 252) . '…' : $title;
    }

    private function cleanupArchiveTopicIfEmpty(int $archiveTopicId): void
    {
        if ($this->topics->activePostIds($archiveTopicId) !== []) {
            return;
        }

        $this->topics->softDelete($archiveTopicId);
        $this->search?->removeTopic($archiveTopicId);
    }

    /**
     * @param array<string, mixed> $sourceTopic
     */
    private function afterSourceTopicChange(array $sourceTopic): void
    {
        $topicId = (int) $sourceTopic['id'];
        $remaining = $this->topics->activePostIds($topicId);

        if ($remaining === []) {
            $this->topics->softDelete($topicId);
            $this->search?->removeTopic($topicId);

            return;
        }

        $this->topics->recalculateLastPostAt($topicId);
        $this->indexArchiveTopic($topicId);
    }

    private function indexArchiveTopic(int $topicId): void
    {
        if ($this->search === null || !$this->search->isEnabled()) {
            return;
        }

        $this->search->indexTopic($topicId);
    }
}
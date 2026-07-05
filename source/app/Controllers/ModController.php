<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\Cache;
use Latch\Core\ModerationTrashService;
use Latch\Core\Response;
use Latch\Support\BulkTopicActionService;
use Latch\Support\ModerationTrashResponder;
use Latch\Support\StaffActionResponder;
use RuntimeException;

final class ModController
{
    use ModerationTrashResponder;
    use StaffActionResponder;

    public function __construct(private readonly Application $app)
    {
    }

    protected function staffApp(): Application
    {
        return $this->app;
    }

    public function toggleLock(array $params): void
    {
        $this->app->auth()->requireMod();
        $this->validateCsrf();

        $topic = $this->findTopic($params['id'] ?? '0');
        $locked = !empty($topic['is_locked']);
        $this->app->topics()->setLocked((int) $topic['id'], !$locked);

        $this->invalidateTopicCache($topic);
        $this->logModAction($locked ? 'topic.unlock' : 'topic.lock', 'topic', (int) $topic['id']);
        $staff = $this->app->auth()->user();
        if ($staff !== null) {
            $action = $locked ? 'unlock' : 'lock';
            $verb = $locked ? 'unlocked' : 'locked';
            $this->app->notificationService()->onStaffTopicAction(
                'topic.' . $action,
                $topic,
                $staff,
                'Your topic "' . $this->topicTitleLabel($topic) . '" was ' . $verb . ' by @' . $staff['username'],
            );
        }

        $this->finishStaffAction(
            true,
            $locked ? 'Topic unlocked.' : 'Topic locked.',
            '/topic/' . $topic['id'],
            ['is_locked' => !$locked],
        );
    }

    public function togglePin(array $params): void
    {
        $this->app->auth()->requireMod();
        $this->validateCsrf();

        $topic = $this->findTopic($params['id'] ?? '0');
        $pinned = !empty($topic['is_pinned']);
        $this->app->topics()->setPinned((int) $topic['id'], !$pinned);

        $this->invalidateTopicCache($topic);
        $this->logModAction($pinned ? 'topic.unpin' : 'topic.pin', 'topic', (int) $topic['id']);
        $staff = $this->app->auth()->user();
        if ($staff !== null) {
            $action = $pinned ? 'unpin' : 'pin';
            $verb = $pinned ? 'unpinned' : 'pinned';
            $this->app->notificationService()->onStaffTopicAction(
                'topic.' . $action,
                $topic,
                $staff,
                'Your topic "' . $this->topicTitleLabel($topic) . '" was ' . $verb . ' by @' . $staff['username'],
            );
        }

        $this->finishStaffAction(
            true,
            $pinned ? 'Topic unpinned.' : 'Topic pinned.',
            '/board/' . $this->boardSlug((int) $topic['board_id']),
            ['is_pinned' => !$pinned],
        );
    }

    public function editTopicTitle(array $params): void
    {
        $this->app->auth()->requireMod();
        $this->editTopicDetails($params);
    }

    public function deleteTopic(array $params): void
    {
        $this->app->auth()->requireMod();
        $this->validateCsrf();

        $topic = $this->findTopic($params['id'] ?? '0');
        if (!empty($topic['deleted_at'])) {
            $this->app->session()->flash('error', 'Topic already removed.');
            Response::redirect('/board/' . $this->boardSlug((int) $topic['board_id']));
        }

        $staff = $this->app->auth()->user();
        $staffId = (int) ($staff['id'] ?? 0);
        $archivedPosts = $this->app->moderationTrash()->archiveTopic((int) $topic['id'], $staffId);
        $this->invalidateTopicCache($topic);
        $this->logModAction('topic.delete', 'topic', (int) $topic['id'], [
            'archived_posts' => $archivedPosts,
        ]);

        if ($staff !== null) {
            $this->app->notificationService()->onStaffTopicAction(
                'topic.delete',
                $topic,
                $staff,
                'Your topic "' . $this->topicTitleLabel($topic) . '" was removed by @' . $staff['username'],
            );
        }

        $boardSlug = $this->boardSlug((int) $topic['board_id']);
        $trashBoard = $this->app->moderationTrash()->trashBoard();
        $trashSlug = (string) ($trashBoard['slug'] ?? ModerationTrashService::BOARD_SLUG);
        $message = $archivedPosts > 0
            ? "Topic archived — {$archivedPosts} post(s) moved to " . ($trashBoard['name'] ?? 'Moderation trash') . '.'
            : 'Topic removed from board.';
        $redirect = $archivedPosts > 0 ? '/board/' . $trashSlug : '/board/' . $boardSlug;

        $this->finishStaffAction(
            true,
            $message,
            $redirect,
            ['redirect' => $redirect],
        );
    }

    public function purgeTrashTopic(array $params): void
    {
        $this->app->auth()->requireMod();
        $this->validateCsrf();

        $topicId = (int) ($params['id'] ?? 0);
        $trashPath = $this->app->moderationTrash()->trashBoardPath();
        $result = $this->app->moderationTrash()->purgeTrashTopic($topicId);
        if ($result === null) {
            $this->finishStaffAction(false, 'Nothing to delete.', $trashPath);
        }

        $restoreTopicIds = [];
        foreach ($result['purged'] as $purgeResult) {
            $restoreTopicId = (int) $purgeResult['restore_topic_id'];
            if ($restoreTopicId > 0) {
                $restoreTopicIds[$restoreTopicId] = true;
            }

            $this->app->invalidateCacheTags([Cache::tagUser((int) $purgeResult['author_user_id'])]);
        }

        foreach (array_keys($restoreTopicIds) as $restoreTopicId) {
            $topic = $this->app->topics()->findById($restoreTopicId);
            if ($topic === null) {
                continue;
            }

            $this->app->topics()->recalculateLastPostAt($restoreTopicId);
            $this->app->indexSearchTopic($restoreTopicId);
            $this->app->invalidateCacheTags([
                Cache::tagTopic($restoreTopicId),
                Cache::tagBoard((int) $topic['board_id']),
                Cache::tagSite(),
            ]);
        }

        $this->app->invalidateCacheTags([
            Cache::tagTopic($topicId),
            Cache::tagSite(),
        ]);

        $count = count($result['purged']);
        $this->logModAction('topic.trash_purge', 'topic', $topicId, ['purged_posts' => $count]);
        $this->finishStaffAction(
            true,
            $count === 1
                ? 'Archived post permanently deleted.'
                : "{$count} archived posts permanently deleted.",
            $trashPath,
        );
    }

    public function editTopicTags(array $params): void
    {
        $this->editTopicDetails($params);
    }

    public function editTopicDetails(array $params): void
    {
        $this->app->auth()->requireLogin();
        $this->validateCsrf();

        $topic = $this->findTopic($params['id'] ?? '0');
        if (!empty($topic['deleted_at'])) {
            $this->app->session()->flash('error', 'This topic has been removed.');
            Response::redirect('/topic/' . $topic['id']);
        }

        $user = $this->app->auth()->user();
        $isMod = $this->app->auth()->isMod();
        $isAuthor = $user !== null && (int) $user['id'] === (int) $topic['user_id'];
        if (!$isMod && !$isAuthor) {
            Response::forbidden('You cannot edit this topic.');
        }

        $topicId = (int) $topic['id'];
        $changed = [];

        if ($isMod && array_key_exists('title', $_POST)) {
            $title = trim((string) $this->app->request()->input('title', ''));
            $titleError = $this->app->inputValidator()->topicTitleError($title);
            if ($titleError !== null) {
                $this->app->session()->flash('error', $titleError);
                Response::redirect('/topic/' . $topicId);
            }

            try {
                $this->app->topics()->updateTitle($topicId, $title);
                $changed[] = 'title';
            } catch (RuntimeException $e) {
                $this->app->session()->flash('error', $e->getMessage());
                Response::redirect('/topic/' . $topicId);
            }
        }

        if (array_key_exists('tags', $_POST) && ($isMod || $isAuthor)) {
            try {
                $tagNames = $this->app->topicTags()->parse(
                    (string) $this->app->request()->input('tags', ''),
                    $this->app->maxTagsPerTopic(),
                );
                $this->app->tags()->syncForTopic($topicId, $tagNames);
                $changed[] = 'tags';
            } catch (RuntimeException $e) {
                $this->app->session()->flash('error', $e->getMessage());
                Response::redirect('/topic/' . $topicId);
            }
        }

        if ($changed === []) {
            Response::forbidden('Nothing to update.');
        }

        $this->app->indexSearchTopic($topicId);
        $this->invalidateTopicCache($topic);
        $this->logModAction('topic.details_edit', 'topic', $topicId, ['changed' => $changed]);

        $this->app->session()->flash('success', 'Topic updated.');
        Response::redirect('/topic/' . $topicId);
    }

    public function postRevisions(array $params): void
    {
        $this->app->auth()->requireMod();

        $postId = (int) ($params['id'] ?? 0);
        $post = $this->app->posts()->findById($postId);
        if ($post === null) {
            Response::notFound('Post not found');
        }

        $topic = $this->app->topics()->findById((int) $post['topic_id']);
        if ($topic === null) {
            Response::notFound('Topic not found');
        }

        $board = $this->app->boards()->findById((int) $topic['board_id']);
        if ($board === null) {
            Response::notFound('Board not found');
        }

        $author = $this->app->users()->findById((int) $post['user_id']);
        $post['author_name'] = (string) ($author['username'] ?? 'unknown');

        $revisions = $this->app->postRevisions()->listForPost($postId);
        $diff = new \Latch\Core\TextDiff();
        $diffs = [];

        $currentBody = (string) $post['body'];
        foreach ($revisions as $revision) {
            $diffs[(int) $revision['id']] = $diff->lines((string) $revision['body'], $currentBody);
            $currentBody = (string) $revision['body'];
        }

        $lastEditor = $revisions[0] ?? null;
        $editTimeline = [
            [
                'kind' => 'created',
                'username' => $post['author_name'],
                'at' => (string) $post['created_at'],
            ],
        ];
        foreach (array_reverse($revisions) as $revision) {
            $editTimeline[] = [
                'kind' => 'edit',
                'username' => (string) $revision['editor_username'],
                'editor_id' => (int) $revision['editor_id'],
                'at' => (string) $revision['created_at'],
                'revision_id' => (int) $revision['id'],
            ];
        }

        $this->app->render('mod/post_revisions.html.twig', [
            'post' => $post,
            'topic' => $topic,
            'board' => $board,
            'revisions' => $revisions,
            'diffs' => $diffs,
            'last_editor' => $lastEditor,
            'edit_timeline' => $editTimeline,
        ]);
    }

    public function moveTopic(array $params): void
    {
        $this->app->auth()->requireMod();
        $this->validateCsrf();

        $topic = $this->findTopic($params['id'] ?? '0');
        $boardId = (int) $this->app->request()->input('board_id', 0);
        $board = $this->app->boards()->findById($boardId);
        if ($board === null) {
            $this->finishStaffAction(false, 'Board not found.', '/topic/' . $topic['id']);
        }

        try {
            $result = $this->app->topics()->moveToBoard((int) $topic['id'], $boardId);
        } catch (RuntimeException $e) {
            $this->finishStaffAction(false, $e->getMessage(), '/topic/' . $topic['id']);
        }

        $this->app->indexSearchTopic((int) $topic['id']);
        $this->app->invalidateCacheTags([
            Cache::tagTopic((int) $topic['id']),
            Cache::tagBoard($result['old_board_id']),
            Cache::tagBoard($result['new_board_id']),
            Cache::tagUser((int) $topic['user_id']),
            Cache::tagSite(),
        ]);

        $this->logModAction('topic.move', 'topic', (int) $topic['id'], [
            'from_board_id' => $result['old_board_id'],
            'to_board_id' => $result['new_board_id'],
        ]);
        $this->notifyTopicAuthor(
            $topic,
            'topic.move',
            'Your topic "' . $this->topicTitleLabel($topic) . '" was moved to ' . $board['name'] . ' by @'
            . ($this->app->auth()->user()['username'] ?? 'staff'),
        );

        $this->finishStaffAction(
            true,
            'Topic moved to ' . $board['name'] . '.',
            '/topic/' . $topic['id'],
            ['redirect' => '/topic/' . $topic['id']],
        );
    }

    public function mergeTopic(array $params): void
    {
        $this->app->auth()->requireMod();
        $this->validateCsrf();

        $topic = $this->findTopic($params['id'] ?? '0');
        $targetId = (int) $this->app->request()->input('target_topic_id', 0);
        if ($targetId <= 0) {
            $this->finishStaffAction(false, 'Enter a valid target topic ID.', '/topic/' . $topic['id']);
        }

        try {
            $result = $this->app->topics()->mergeInto((int) $topic['id'], $targetId);
        } catch (RuntimeException $e) {
            $this->finishStaffAction(false, $e->getMessage(), '/topic/' . $topic['id']);
        }

        $target = $this->app->topics()->findById($targetId);
        if ($target === null) {
            $this->finishStaffAction(false, 'Merged topic could not be loaded.', '/topic/' . $targetId);
        }

        $this->mergeTopicTags((int) $topic['id'], $targetId);
        $this->app->search()->removeTopic((int) $topic['id']);
        $this->app->indexSearchTopic($targetId);

        $this->app->invalidateCacheTags([
            Cache::tagTopic((int) $topic['id']),
            Cache::tagTopic($targetId),
            Cache::tagBoard((int) $topic['board_id']),
            Cache::tagBoard((int) $target['board_id']),
            Cache::tagUser((int) $topic['user_id']),
            Cache::tagUser((int) $target['user_id']),
            Cache::tagSite(),
        ]);

        $this->logModAction('topic.merge', 'topic', (int) $topic['id'], [
            'target_topic_id' => $targetId,
            'posts_moved' => $result['posts_moved'],
        ]);
        $this->notifyTopicAuthor(
            $topic,
            'topic.merge',
            'Your topic "' . $this->topicTitleLabel($topic) . '" was merged into "'
            . $this->topicTitleLabel($target) . '" by @' . ($this->app->auth()->user()['username'] ?? 'staff'),
        );

        $this->finishStaffAction(
            true,
            'Topic merged into #' . $targetId . ' (' . $result['posts_moved'] . ' posts moved).',
            '/topic/' . $targetId,
            ['redirect' => '/topic/' . $targetId],
        );
    }

    public function splitTopic(array $params): void
    {
        $this->app->auth()->requireMod();
        $this->validateCsrf();

        $topic = $this->findTopic($params['id'] ?? '0');
        $postId = (int) $this->app->request()->input('post_id', 0);
        $title = trim((string) $this->app->request()->input('title', ''));

        $titleError = $this->app->inputValidator()->topicTitleError($title);
        if ($titleError !== null) {
            $this->finishStaffAction(false, $titleError, '/topic/' . $topic['id']);
        }

        try {
            $result = $this->app->topics()->splitFromPost((int) $topic['id'], $postId, $title);
        } catch (RuntimeException $e) {
            $this->finishStaffAction(false, $e->getMessage(), '/topic/' . $topic['id']);
        }

        $newTopicId = $result['new_topic_id'];
        $this->app->indexSearchTopic((int) $topic['id']);
        $this->app->indexSearchTopic($newTopicId);

        $this->app->invalidateCacheTags([
            Cache::tagTopic((int) $topic['id']),
            Cache::tagTopic($newTopicId),
            Cache::tagBoard((int) $topic['board_id']),
            Cache::tagSite(),
        ]);

        $this->logModAction('topic.split', 'topic', (int) $topic['id'], [
            'new_topic_id' => $newTopicId,
            'from_post_id' => $postId,
            'posts_moved' => $result['posts_moved'],
        ]);

        $this->finishStaffAction(
            true,
            'Split complete — ' . $result['posts_moved'] . ' posts moved to new topic.',
            '/topic/' . $newTopicId,
            ['redirect' => '/topic/' . $newTopicId],
        );
    }

    public function deletePost(array $params): void
    {
        $this->app->auth()->requireMod();
        $this->validateCsrf();

        $postId = (int) ($params['id'] ?? 0);
        $topicId = (int) $this->app->request()->input('topic_id', 0);
        $this->trashPostsByIds([$postId], $topicId > 0 ? $topicId : null);
    }

    public function restoreTrashedPost(array $params): void
    {
        $this->app->auth()->requireMod();
        $this->validateCsrf();
        $this->finishTrashRestore((int) ($params['id'] ?? 0));
    }

    public function purgeTrashedPost(array $params): void
    {
        $this->app->auth()->requireMod();
        $this->validateCsrf();
        $this->finishTrashPurge((int) ($params['id'] ?? 0));
    }

    public function bulkTopics(array $params = []): void
    {
        $this->app->auth()->requireMod();
        $this->validateCsrf();

        $action = trim((string) $this->app->request()->input('action', ''));
        $allowed = ['pin', 'unpin', 'lock', 'unlock', 'delete'];
        if (!in_array($action, $allowed, true)) {
            $this->finishStaffAction(false, 'Unknown action.', $this->bulkTopicsRedirect());
        }

        $ids = $this->parseTopicIds();
        if ($ids === []) {
            $this->finishStaffAction(false, 'Select at least one topic.', $this->bulkTopicsRedirect());
        }

        $staffId = (int) ($this->app->auth()->user()['id'] ?? 0);
        $result = (new BulkTopicActionService($this->app))->execute($action, $ids, $staffId);

        $this->finishStaffAction(
            $result['ok'],
            $result['message'],
            $this->bulkTopicsRedirect(),
            [
                'processed' => $result['processed'],
                'skipped' => $result['skipped'],
            ],
        );
    }

    public function trashPosts(array $params = []): void
    {
        $this->app->auth()->requireMod();
        $this->validateCsrf();

        $topicId = (int) $this->app->request()->input('topic_id', 0);
        $ids = $this->parsePostIds();
        $this->trashPostsByIds($ids, $topicId > 0 ? $topicId : null);
    }

    public function quarantinePosts(array $params = []): void
    {
        $this->app->auth()->requireMod();
        $this->validateCsrf();

        $topicId = (int) $this->app->request()->input('topic_id', 0);
        $ids = $this->parsePostIds();
        if ($ids === []) {
            $this->finishStaffAction(false, 'Select at least one post.', $this->topicRedirect($topicId));
        }

        $topic = $topicId > 0 ? $this->app->topics()->findById($topicId) : null;
        $quarantined = 0;

        foreach ($ids as $id) {
            $post = $this->app->posts()->findById($id);
            if ($post === null || ($post['deleted_at'] ?? null) !== null || ($post['trashed_at'] ?? null) !== null) {
                continue;
            }
            if ($topic !== null && (int) $post['topic_id'] !== (int) $topic['id']) {
                continue;
            }
            if (!$this->app->posts()->staffQuarantine($id)) {
                continue;
            }
            $quarantined++;
            $this->logModAction('post.quarantine', 'post', $id, ['manual' => true]);
        }

        if ($topic !== null && $quarantined > 0) {
            $this->app->indexSearchTopic((int) $topic['id']);
            $this->invalidateTopicCache($topic);
        }

        $message = $quarantined > 0
            ? "Quarantined {$quarantined} post(s)."
            : 'No posts were quarantined.';
        $this->finishStaffAction(
            $quarantined > 0,
            $message,
            $this->topicRedirect($topicId),
            ['quarantined' => $quarantined],
        );
    }

    public function liftQuarantinePosts(array $params = []): void
    {
        $this->app->auth()->requireMod();
        $this->validateCsrf();

        $topicId = (int) $this->app->request()->input('topic_id', 0);
        $ids = $this->parsePostIds();
        if ($ids === []) {
            $this->finishStaffAction(false, 'Select at least one post.', $this->topicRedirect($topicId));
        }

        $topic = $topicId > 0 ? $this->app->topics()->findById($topicId) : null;
        $staff = $this->app->auth()->user();
        $staffId = (int) ($staff['id'] ?? 0);
        $ip = $this->app->request()->ip();
        $lifted = 0;

        foreach ($ids as $id) {
            $post = $this->app->posts()->findById($id);
            if ($post === null || ($post['deleted_at'] ?? null) !== null || ($post['trashed_at'] ?? null) !== null) {
                continue;
            }
            if ($topic !== null && (int) $post['topic_id'] !== (int) $topic['id']) {
                continue;
            }
            if (!$this->app->posts()->staffLiftQuarantine($id)) {
                continue;
            }
            $lifted++;
            $this->logModAction('post.quarantine_lift', 'post', $id, ['manual' => true]);
            $this->app->securityLog()->log('quarantine_lift', [
                'ip' => $ip,
                'user_id' => $staffId,
                'target_type' => 'post',
                'target_id' => $id,
                'meta' => ['manual' => true],
            ]);
        }

        if ($topic !== null && $lifted > 0) {
            $this->app->indexSearchTopic((int) $topic['id']);
            $this->invalidateTopicCache($topic);
        }

        $message = $lifted > 0
            ? "Lifted quarantine on {$lifted} post(s)."
            : 'No quarantined posts were updated.';
        $this->finishStaffAction(
            $lifted > 0,
            $message,
            $this->topicRedirect($topicId),
            ['lifted' => $lifted],
        );
    }

    /**
     * @param list<int> $ids
     */
    private function trashPostsByIds(array $ids, ?int $expectedTopicId): void
    {
        if ($ids === []) {
            $this->finishStaffAction(false, 'Select at least one post.', $this->topicRedirect($expectedTopicId ?? 0));
        }

        $staff = $this->app->auth()->user();
        $staffId = (int) ($staff['id'] ?? 0);
        $topic = $expectedTopicId !== null && $expectedTopicId > 0
            ? $this->app->topics()->findById($expectedTopicId)
            : null;
        $trashed = 0;

        foreach ($ids as $id) {
            $post = $this->app->posts()->findById($id);
            if ($post === null || ($post['deleted_at'] ?? null) !== null || ($post['trashed_at'] ?? null) !== null) {
                continue;
            }
            if ($topic !== null && (int) $post['topic_id'] !== (int) $topic['id']) {
                continue;
            }

            $postTopic = $this->app->topics()->findById((int) $post['topic_id']);
            if ($postTopic === null) {
                continue;
            }

            if ($this->app->moderationTrash()->archivePost($id, $staffId) === null) {
                continue;
            }

            $trashed++;
            $boardId = (int) $postTopic['board_id'];
            $this->logModAction('post.trash', 'post', $id, [
                'topic_id' => (int) $postTopic['id'],
                'board_id' => $boardId,
            ]);

            if ($staff !== null) {
                $this->app->notificationService()->onStaffPostAction(
                    'post.trash',
                    $post,
                    $postTopic,
                    $staff,
                    'Your post in "' . $this->topicTitleLabel($postTopic) . '" was removed for review by @' . $staff['username'],
                );
            }
        }

        if ($topic !== null && $trashed > 0) {
            $this->app->topics()->recalculateLastPostAt((int) $topic['id']);
            $this->app->indexSearchTopic((int) $topic['id']);
            $this->invalidateTopicCache($topic);
        }

        $redirect = $this->topicRedirect($expectedTopicId ?? (int) ($topic['id'] ?? 0));
        $trashBoard = $this->app->moderationTrash()->trashBoard();
        $trashName = (string) ($trashBoard['name'] ?? 'Moderation trash');
        $message = $trashed > 0
            ? "Removed {$trashed} post(s) to {$trashName} (one topic per post)."
            : 'No posts were removed.';
        $this->finishStaffAction($trashed > 0, $message, $redirect, ['trashed' => $trashed]);
    }

    /**
     * @return list<int>
     */
    private function parseTopicIds(): array
    {
        $raw = $this->app->request()->input('topic_ids', []);
        if (!is_array($raw)) {
            $raw = explode(',', (string) $raw);
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function bulkTopicsRedirect(): string
    {
        $slug = trim((string) $this->app->request()->input('board_slug', ''));
        if ($slug === '') {
            return '/';
        }

        $path = '/board/' . rawurlencode($slug);
        $query = [];
        $page = max(1, (int) $this->app->request()->input('page', 0));
        if ($page > 1) {
            $query['page'] = (string) $page;
        }
        $tag = trim((string) $this->app->request()->input('tag', ''));
        if ($tag !== '') {
            $query['tag'] = $tag;
        }
        $sort = trim((string) $this->app->request()->input('sort', ''));
        if ($sort !== '' && $sort !== 'activity') {
            $query['sort'] = $sort;
        }

        if ($query === []) {
            return $path;
        }

        return $path . '?' . http_build_query($query);
    }

    /**
     * @return list<int>
     */
    private function parsePostIds(): array
    {
        $raw = $this->app->request()->input('post_ids', []);
        if (!is_array($raw)) {
            $raw = explode(',', (string) $raw);
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function topicRedirect(int $topicId): string
    {
        return $topicId > 0 ? '/topic/' . $topicId : '/';
    }

    private function validateCsrf(): void
    {
        if ($this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            return;
        }

        if ($this->wantsJson()) {
            Response::json(['ok' => false, 'message' => 'Invalid form token.'], 403);
        }

        Response::forbidden('Invalid form token.');
    }

    private function findTopic(string $id): array
    {
        $topic = $this->app->topics()->findById((int) $id);
        if ($topic === null) {
            Response::notFound('Topic not found');
        }

        return $topic;
    }

    private function boardSlug(int $boardId): string
    {
        $board = $this->app->boards()->findById($boardId);

        return $board['slug'] ?? '';
    }

    private function invalidateTopicCache(array $topic): void
    {
        $this->app->invalidateCacheTags([
            Cache::tagTopic((int) $topic['id']),
            Cache::tagBoard((int) $topic['board_id']),
            Cache::tagUser((int) $topic['user_id']),
            Cache::tagSite(),
        ]);
    }

    /**
     * @param array<string, mixed> $topic
     */
    private function topicTitleLabel(array $topic): string
    {
        $title = (string) ($topic['title'] ?? '');

        return mb_strlen($title) <= 80 ? $title : mb_substr($title, 0, 79) . '…';
    }

    private function logModAction(string $action, string $targetType, int $targetId, array $meta = []): void
    {
        $user = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($user['id'] ?? 0),
            $action,
            $targetType,
            $targetId,
            $this->app->request()->ip(),
            $meta,
        );
    }

    /**
     * @param array<string, mixed> $topic
     */
    private function notifyTopicAuthor(array $topic, string $action, string $message): void
    {
        $staff = $this->app->auth()->user();
        if ($staff === null) {
            return;
        }

        $this->app->notificationService()->onStaffTopicAction($action, $topic, $staff, $message);
    }

    protected function recordTrashRestore(int $postId, int $topicId): void
    {
        $this->logModAction('post.trash_restore', 'post', $postId, ['topic_id' => $topicId]);
    }

    protected function recordTrashPurge(int $postId, int $topicId): void
    {
        $this->logModAction('post.trash_purge', 'post', $postId, ['topic_id' => $topicId]);
    }

    private function mergeTopicTags(int $sourceTopicId, int $targetTopicId): void
    {
        $sourceNames = array_map(
            static fn (array $tag): string => (string) $tag['name'],
            $this->app->tags()->forTopic($sourceTopicId),
        );
        $targetNames = array_map(
            static fn (array $tag): string => (string) $tag['name'],
            $this->app->tags()->forTopic($targetTopicId),
        );
        $merged = array_values(array_unique(array_merge($targetNames, $sourceNames)));
        if ($merged === []) {
            return;
        }

        try {
            $this->app->tags()->syncForTopic($targetTopicId, $merged);
        } catch (RuntimeException) {
            /* non-fatal */
        }
    }
}
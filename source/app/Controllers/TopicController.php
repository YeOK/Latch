<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\BoardAcl;
use Latch\Core\Cache;
use Latch\Core\Response;
use Latch\Core\SeoMeta;
use Latch\Support\PostListSort;
use RuntimeException;

final class TopicController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function show(array $params): void
    {
        $topic = $this->findTopic($params['id'] ?? '0');
        $board = $this->app->boards()->findById((int) $topic['board_id']);

        if ($board === null) {
            Response::notFound('Board not found');
        }

        $this->guardRead($board);

        if (!empty($topic['deleted_at']) && !$this->app->auth()->isMod()) {
            Response::notFound('Topic not found');
        }

        $user = $this->app->auth()->user();
        $isMod = $this->app->auth()->isMod();
        $viewerId = $user !== null ? (int) $user['id'] : null;

        // Hide spam/pending topics from guests and non-authors until an approved post exists.
        if (
            !$isMod
            && !$this->app->posts()->topicHasApprovedPost((int) $topic['id'])
            && ($viewerId === null || $viewerId !== (int) $topic['user_id'])
        ) {
            Response::notFound('Topic not found');
        }

        $isTrashBoard = $this->app->moderationTrash()->isTrashBoard($board);
        $postSort = PostListSort::normalize($this->app->request()->input('sort'));
        $postBundle = $this->loadTopicPosts(
            $topic,
            $board,
            $viewerId,
            $isMod,
            $isTrashBoard,
            $postSort,
            $user,
        );
        $posts = $postBundle['posts'];

        // Guest-only page cache when the board is publicly readable.
        $cacheOptions = null;
        if (
            !$this->app->auth()->check()
            && !$this->app->membersOnly()
            && BoardAcl::isPublicRead($board)
            && $postSort === PostListSort::OLDEST
            && !$postBundle['show_latest']
            && $postBundle['cursor_after'] === 0
        ) {
            $cacheOptions = [
                'route' => '/topic/' . $topic['id'],
                'tags' => [
                    Cache::tagTopic((int) $topic['id']),
                    Cache::tagBoard((int) $board['id']),
                    Cache::tagSite(),
                ],
            ];
        }

        if ($viewerId !== null) {
            $this->app->markTopicReadForUser($viewerId, (int) $topic['id'], $posts);
        }

        $tags = $this->app->tags()->forTopic((int) $topic['id']);
        $can_edit_tags = $isMod
            || ($user !== null && (int) $user['id'] === (int) $topic['user_id']);
        $is_watching = $viewerId !== null
            && $this->app->topicWatches()->isWatching($viewerId, (int) $topic['id']);

        $loggedIn = $this->app->auth()->check();
        $viewerRole = $this->app->viewerRole();
        $membersOnly = $this->app->membersOnly();

        $modBoards = [];
        if ($isMod) {
            $modBoards = array_values(array_filter(
                $this->app->boards()->all(),
                static fn (array $row): bool => empty($row['deleted_at'] ?? null),
            ));
        }

        $firstPost = $posts[0] ?? null;
        $topicDescription = $firstPost !== null
            ? $this->app->rss()->plainExcerpt((string) $firstPost['body'], SeoMeta::DESCRIPTION_MAX)
            : (string) $topic['title'];
        $publishedTime = $firstPost !== null
            ? (string) ($firstPost['created_at'] ?? '')
            : (string) ($topic['created_at'] ?? '');

        $board = $this->app->enrichBoardWithIcon($board);

        $this->app->render('topic/show.html.twig', [
            'topic' => $topic,
            'board' => $board,
            'plugin_topic_actions' => $this->app->collectTopicActions($topic, $board),
            'posts' => $posts,
            'post_total' => $postBundle['total'],
            'posts_paginated' => $postBundle['paginated'],
            'posts_has_more' => $postBundle['has_more'],
            'posts_cursor_after' => $postBundle['cursor_after'],
            'posts_show_latest' => $postBundle['show_latest'],
            'posts_has_earlier' => $postBundle['has_earlier'],
            'post_sort' => $postSort,
            'post_sort_options' => PostListSort::translatedLabels($this->app->trans(...)),
            'tags' => $tags,
            'can_edit_tags' => $can_edit_tags && empty($topic['deleted_at']),
            'is_watching' => $is_watching,
            'max_tags' => $this->app->maxTagsPerTopic(),
            'report_categories' => $this->app->reportReasons()->translatedCategories($this->app->trans(...)),
            'post_edit_window_minutes' => $this->app->postEditWindowMinutes(),
            'can_reply' => !$isTrashBoard && $this->app->boards()->canReply(
                $board,
                $loggedIn,
                $viewerRole,
                $membersOnly,
                $this->app->viewerReputationRank(),
            ),
            'is_mod_trash_board' => $isTrashBoard,
            'mod_boards' => $modBoards,
            'mod_boards_json' => json_encode(array_map(
                static fn (array $board): array => [
                    'id' => (int) $board['id'],
                    'name' => (string) $board['name'],
                ],
                $modBoards,
            ), JSON_THROW_ON_ERROR),
            'seo' => SeoMeta::forTopic(
                $this->app->siteUrl(),
                $this->app->siteName(),
                (string) $topic['title'],
                (int) $topic['id'],
                $topicDescription,
                $publishedTime !== '' ? $publishedTime : null,
                $membersOnly,
            )->toArray(),
        ], $cacheOptions);
    }

    public function postsPartial(array $params): void
    {
        $topic = $this->findTopic($params['id'] ?? '0');
        $board = $this->app->boards()->findById((int) $topic['board_id']);

        if ($board === null) {
            Response::notFound('Board not found');
        }

        $this->guardRead($board);

        if (!empty($topic['deleted_at']) && !$this->app->auth()->isMod()) {
            Response::notFound('Topic not found');
        }

        $user = $this->app->auth()->user();
        $isMod = $this->app->auth()->isMod();
        $viewerId = $user !== null ? (int) $user['id'] : null;

        if (
            !$isMod
            && !$this->app->posts()->topicHasApprovedPost((int) $topic['id'])
            && ($viewerId === null || $viewerId !== (int) $topic['user_id'])
        ) {
            Response::notFound('Topic not found');
        }

        $isTrashBoard = $this->app->moderationTrash()->isTrashBoard($board);
        $afterId = max(0, (int) $this->app->request()->input('after', 0));
        if ($afterId <= 0) {
            Response::json(['error' => 'after_required'], 400);
        }

        $perPage = $this->app->postsPerPage();
        $posts = $this->app->posts()->listByTopicCursor(
            (int) $topic['id'],
            $viewerId,
            $isMod,
            $isTrashBoard,
            $perPage,
            $afterId,
        );
        $baseIndex = $this->app->posts()->countVisibleUpToId(
            (int) $topic['id'],
            $afterId,
            $viewerId,
            $isMod,
            $isTrashBoard,
        );
        $posts = $this->finalizeTopicPosts($posts, $topic, $board, $user, $isMod, $isTrashBoard, $baseIndex);

        $lastId = $posts !== [] ? (int) $posts[array_key_last($posts)]['id'] : $afterId;
        $hasMore = $this->app->posts()->hasPostsAfter(
            (int) $topic['id'],
            $lastId,
            $viewerId,
            $isMod,
            $isTrashBoard,
        );

        $html = $this->app->renderPartial('partials/topic_posts.html.twig', [
            'topic' => $topic,
            'board' => $board,
            'posts' => $posts,
            'report_categories' => $this->app->reportReasons()->translatedCategories($this->app->trans(...)),
            'is_mod_trash_board' => $isTrashBoard,
        ]);

        Response::json([
            'html' => $html,
            'has_more' => $hasMore,
            'cursor_after' => $lastId,
        ]);
    }

    /**
     * @param array<string, mixed>      $topic
     * @param array<string, mixed>      $board
     * @param array<string, mixed>|null $user
     * @return array{
     *     posts: list<array<string, mixed>>,
     *     total: int,
     *     paginated: bool,
     *     has_more: bool,
     *     cursor_after: int,
     *     show_latest: bool,
     *     has_earlier: bool
     * }
     */
    private function loadTopicPosts(
        array $topic,
        array $board,
        ?int $viewerId,
        bool $isMod,
        bool $isTrashBoard,
        string $postSort,
        ?array $user,
    ): array {
        $topicId = (int) $topic['id'];
        $perPage = $this->app->postsPerPage();
        $threshold = $this->app->topicPaginationThreshold();
        $total = $this->app->posts()->countVisibleByTopic($topicId, $viewerId, $isMod, $isTrashBoard);
        $paginate = $postSort === PostListSort::OLDEST && $total > $threshold;
        $showLatest = $paginate && $this->app->request()->input('latest') === '1';

        if (!$paginate) {
            $posts = $this->app->posts()->listByTopic($topicId, false, $viewerId, $isMod, $isTrashBoard);
            $posts = $this->finalizeTopicPosts($posts, $topic, $board, $user, $isMod, $isTrashBoard, 0);
            $posts = PostListSort::sortPosts($posts, $postSort);

            return [
                'posts' => $posts,
                'total' => $total,
                'paginated' => false,
                'has_more' => false,
                'cursor_after' => 0,
                'show_latest' => false,
                'has_earlier' => false,
            ];
        }

        if ($showLatest) {
            $posts = $this->app->posts()->listByTopicTail($topicId, $viewerId, $isMod, $isTrashBoard, $perPage);
            $baseIndex = max(0, $total - count($posts));
            $posts = $this->finalizeTopicPosts($posts, $topic, $board, $user, $isMod, $isTrashBoard, $baseIndex);
            $firstId = $posts !== [] ? (int) $posts[0]['id'] : 0;
            $hasEarlier = $firstId > 0 && $this->app->posts()->hasPostsBefore(
                $topicId,
                $firstId,
                $viewerId,
                $isMod,
                $isTrashBoard,
            );

            return [
                'posts' => $posts,
                'total' => $total,
                'paginated' => true,
                'has_more' => false,
                'cursor_after' => $posts !== [] ? (int) $posts[array_key_last($posts)]['id'] : 0,
                'show_latest' => true,
                'has_earlier' => $hasEarlier,
            ];
        }

        $posts = $this->app->posts()->listByTopicCursor(
            $topicId,
            $viewerId,
            $isMod,
            $isTrashBoard,
            $perPage,
            null,
        );
        $posts = $this->finalizeTopicPosts($posts, $topic, $board, $user, $isMod, $isTrashBoard, 0);
        $lastId = $posts !== [] ? (int) $posts[array_key_last($posts)]['id'] : 0;
        $hasMore = $lastId > 0 && $this->app->posts()->hasPostsAfter(
            $topicId,
            $lastId,
            $viewerId,
            $isMod,
            $isTrashBoard,
        );

        return [
            'posts' => $posts,
            'total' => $total,
            'paginated' => true,
            'has_more' => $hasMore,
            'cursor_after' => $lastId,
            'show_latest' => false,
            'has_earlier' => false,
        ];
    }

    /**
     * @param list<array<string, mixed>> $posts
     * @param array<string, mixed>      $topic
     * @param array<string, mixed>      $board
     * @param array<string, mixed>|null $user
     * @return list<array<string, mixed>>
     */
    private function finalizeTopicPosts(
        array $posts,
        array $topic,
        array $board,
        ?array $user,
        bool $isMod,
        bool $isTrashBoard,
        int $baseIndex,
    ): array {
        $posts = $this->app->enrichPostsWithAvatars($posts);
        foreach ($posts as $i => $post) {
            $posts[$i]['chronological_index'] = $baseIndex + $i + 1;
        }
        $posts = $this->enrichPostsForEdit($posts, $topic, $user, $isMod);
        if ($isTrashBoard) {
            $posts = $this->enrichTrashArchivePosts($posts);
        }

        return $posts;
    }

    /**
     * @param list<array<string, mixed>> $posts
     * @param array<string, mixed> $topic
     * @param array<string, mixed>|null $user
     * @return list<array<string, mixed>>
     */
    private function enrichPostsForEdit(array $posts, array $topic, ?array $user, bool $isMod): array
    {
        $editedPostIds = [];
        foreach ($posts as $post) {
            if ($post['updated_at'] !== null && $post['updated_at'] !== '') {
                $editedPostIds[] = (int) $post['id'];
            }
        }

        $latestEditors = $editedPostIds !== []
            ? $this->app->postRevisions()->latestEditorsForPosts($editedPostIds)
            : [];

        foreach ($posts as $i => $post) {
            $posts[$i]['can_edit'] = $this->app->canUserEditPost($post, $topic, $user, $isMod);
            $posts[$i]['revision_count'] = $isMod
                ? $this->app->postRevisions()->countForPost((int) $post['id'])
                : 0;

            $editor = $latestEditors[(int) $post['id']] ?? null;
            $posts[$i]['last_editor_username'] = $editor['editor_username'] ?? null;
            $posts[$i]['last_editor_id'] = $editor['editor_id'] ?? null;
            $posts[$i]['last_edited_at'] = $editor['edited_at'] ?? ($post['updated_at'] ?? null);
        }

        return $posts;
    }

    /**
     * @param list<array<string, mixed>> $posts
     * @return list<array<string, mixed>>
     */
    private function enrichTrashArchivePosts(array $posts): array
    {
        foreach ($posts as $i => $post) {
            $restoreBoardId = (int) ($post['trash_restore_board_id'] ?? 0);
            $restoreTopicId = (int) ($post['trash_restore_topic_id'] ?? 0);
            $restoreBoard = $restoreBoardId > 0 ? $this->app->boards()->findById($restoreBoardId) : null;
            $restoreTopic = $restoreTopicId > 0 ? $this->app->topics()->findById($restoreTopicId) : null;

            $posts[$i]['restore_board_name'] = (string) ($restoreBoard['name'] ?? '');
            $posts[$i]['restore_board_slug'] = (string) ($restoreBoard['slug'] ?? '');
            $posts[$i]['restore_topic_title'] = (string) ($restoreTopic['title'] ?? '');
            $posts[$i]['is_trashed_archive'] = true;
            $posts[$i]['is_restorable_archive'] = ($post['trashed_at'] ?? null) !== null && $restoreTopicId > 0;
        }

        return $posts;
    }

    public function reply(array $params): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            $this->app->session()->flash('error', 'Invalid form token.');
            Response::redirect('/topic/' . ($params['id'] ?? ''));
        }

        $topic = $this->findTopic($params['id'] ?? '0');
        $board = $this->app->boards()->findById((int) $topic['board_id']);

        if ($board === null) {
            Response::notFound('Board not found');
        }

        $this->guardRead($board);

        if (!empty($topic['deleted_at'])) {
            $this->app->session()->flash('error', 'This topic has been removed.');
            Response::redirect('/board/' . ($board['slug'] ?? ''));
        }

        if (!$this->app->boards()->canReply(
            $board,
            true,
            $this->app->viewerRole(),
            $this->app->membersOnly(),
            $this->app->viewerReputationRank(),
        )) {
            $this->app->session()->flash('error', 'You cannot reply in this board.');
            Response::redirect('/topic/' . $topic['id']);
        }

        if (!empty($topic['is_locked']) && !$this->app->auth()->isMod()) {
            $this->app->session()->flash('error', 'This topic is locked.');
            Response::redirect('/topic/' . $topic['id']);
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        if ($this->app->rateLimiter()->exceedsPostLimit($user, 10, 10)) {
            $this->app->session()->flash('error', 'You are posting too quickly. Wait a few minutes.');
            Response::redirect('/topic/' . $topic['id']);
        }

        if ($this->app->spamGuard()->honeypotTriggered()) {
            $this->app->spamGuard()->logHoneypot((int) $user['id']);
            Response::redirect('/topic/' . $topic['id'] . '#latest');
        }

        $body = trim((string) $this->app->request()->input('body', ''));

        $bodyError = $this->app->inputValidator()->postBodyError($body);
        if ($bodyError !== null) {
            $this->app->session()->flash('error', $bodyError);
            Response::redirect('/topic/' . $topic['id']);
        }

        $linkError = $this->app->spamGuard()->linkLimitError($body, $user);
        if ($linkError !== null) {
            $this->app->session()->flash('error', $linkError);
            Response::redirect('/topic/' . $topic['id']);
        }

        $approvalStatus = $this->app->spamGuard()->approvalStatusForUser($user);

        $saveContext = new \Latch\Core\Plugins\PostSaveContext(
            $body,
            $user,
            $board,
            $topic,
            'reply',
        );
        $rejectReason = $this->app->applyPostBeforeSave($saveContext);
        if ($rejectReason !== null) {
            $this->app->session()->flash('error', $rejectReason);
            Response::redirect('/topic/' . $topic['id']);
        }
        $body = $saveContext->body;

        try {
            $post = $this->app->posts()->create((int) $topic['id'], (int) $user['id'], $body, null, $approvalStatus);
            if ($approvalStatus === \Latch\Models\PostRepository::APPROVAL_APPROVED) {
                $this->app->topics()->touchLastPost((int) $topic['id']);
                $this->app->indexSearchTopic((int) $topic['id']);
                $this->app->notificationService()->onReply($topic, $post, $user);
                $this->app->participateInTopic((int) $user['id'], (int) $topic['id'], [$post]);
                $this->app->enqueueReputationUpdate((int) $user['id']);
            } else {
                $this->app->topicWatches()->watch((int) $user['id'], (int) $topic['id']);
                $this->app->notificationService()->onPostPendingApproval($topic, $post, $user, false);
            }

            $saveContext->post = $post;
            $this->app->firePostAfterSave($saveContext);
        } catch (RuntimeException $e) {
            $this->app->session()->flash('error', $e->getMessage());
            Response::redirect('/topic/' . $topic['id']);
        }

        $this->app->invalidateCacheTags([
            Cache::tagTopic((int) $topic['id']),
            Cache::tagBoard((int) $board['id']),
            Cache::tagUser((int) $user['id']),
            Cache::tagSite(),
        ]);

        if ($approvalStatus === \Latch\Models\PostRepository::APPROVAL_PENDING) {
            $this->app->session()->flash('success', 'Your reply was submitted and is awaiting staff approval.');
        }

        Response::redirect('/topic/' . $topic['id'] . '#latest');
    }

    public function editPost(array $params): void
    {
        $this->app->auth()->requireLogin();

        $postId = (int) ($params['id'] ?? 0);
        $post = $this->app->posts()->findById($postId);
        $fallbackRedirect = $post !== null ? '/topic/' . $post['topic_id'] : '/';

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            $this->app->session()->flash('error', 'Invalid form token.');
            Response::redirect($fallbackRedirect);
        }

        if ($post === null) {
            Response::notFound('Post not found');
        }
        if ($post['deleted_at'] !== null) {
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

        $this->guardRead($board);

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $isMod = $this->app->auth()->isMod();
        $redirect = '/topic/' . $topic['id'] . '#post-' . $postId;

        if (!$this->app->canUserEditPost($post, $topic, $user, $isMod)) {
            $window = $this->app->postEditWindowMinutes();
            $author = $this->app->users()->findById((int) $post['user_id']);
            $authorIsAdmin = $author !== null && (string) $author['role'] === \Latch\Core\Auth::ROLE_ADMIN;
            $message = ($isMod && !$this->app->auth()->isAdmin() && $authorIsAdmin)
                ? 'Moderators cannot edit administrator posts.'
                : ((!$isMod && $window > 0 && (int) $user['id'] === (int) $post['user_id'])
                    ? 'The edit window for this post has expired.'
                    : 'You cannot edit this post.');
            $this->app->session()->flash('error', $message);
            Response::redirect($redirect);
        }

        if (!empty($topic['deleted_at'])) {
            $this->app->session()->flash('error', 'This topic has been removed.');
            Response::redirect('/board/' . ($board['slug'] ?? ''));
        }

        if ($this->app->spamGuard()->honeypotTriggered()) {
            $this->app->spamGuard()->logHoneypot((int) $user['id']);
            Response::redirect($redirect);
        }

        $body = trim((string) $this->app->request()->input('body', ''));

        $bodyError = $this->app->inputValidator()->postBodyError($body);
        if ($bodyError !== null) {
            $this->app->session()->flash('error', $bodyError);
            Response::redirect($redirect);
        }

        $linkError = $this->app->spamGuard()->linkLimitError($body, $user);
        if ($linkError !== null) {
            $this->app->session()->flash('error', $linkError);
            Response::redirect($redirect);
        }

        if ($body === $post['body']) {
            $this->app->session()->flash('success', 'No changes to save.');
            Response::redirect($redirect);
        }

        $oldBody = (string) $post['body'];

        $saveContext = new \Latch\Core\Plugins\PostSaveContext(
            $body,
            $user,
            $board,
            $topic,
            'edit',
            $post,
        );
        $rejectReason = $this->app->applyPostBeforeSave($saveContext);
        if ($rejectReason !== null) {
            $this->app->session()->flash('error', $rejectReason);
            Response::redirect($redirect);
        }
        $body = $saveContext->body;

        try {
            $this->app->postRevisions()->save($postId, (int) $user['id'], $oldBody);
            $this->app->posts()->updateBody($postId, $body);
        } catch (RuntimeException $e) {
            $this->app->session()->flash('error', $e->getMessage());
            Response::redirect($redirect);
        }

        $this->app->indexSearchTopic((int) $topic['id']);
        $this->invalidateTopicCaches($topic, $board, (int) $post['user_id']);

        $updatedPost = $this->app->posts()->findById($postId);
        if ($updatedPost !== null) {
            $this->app->notificationService()->onPostEdit($topic, $updatedPost, $user, $oldBody, $body);
            $saveContext->post = $updatedPost;
            $this->app->firePostAfterSave($saveContext);
        }

        $this->app->auditLog()->record(
            (int) $user['id'],
            'post.edit',
            'post',
            $postId,
            $this->app->request()->ip(),
        );

        $this->app->session()->flash('success', 'Post updated.');
        Response::redirect($redirect);
    }

    /**
     * @param array<string, mixed> $topic
     * @param array<string, mixed> $board
     */
    private function invalidateTopicCaches(array $topic, array $board, int $postAuthorId): void
    {
        $this->app->invalidateCacheTags([
            Cache::tagTopic((int) $topic['id']),
            Cache::tagBoard((int) $board['id']),
            Cache::tagUser($postAuthorId),
            Cache::tagSite(),
        ]);
    }

    private function findTopic(string $id): array
    {
        $topic = $this->app->topics()->findById((int) $id);
        if ($topic === null) {
            Response::notFound('Topic not found');
        }

        return $topic;
    }

    private function guardRead(array $board): void
    {
        $loggedIn = $this->app->auth()->check();
        if (!$this->app->boards()->canRead(
            $board,
            $loggedIn,
            $this->app->membersOnly(),
            $this->app->viewerRole(),
            $this->app->viewerReputationRank(),
        )) {
            if (!$loggedIn) {
                $this->app->session()->flash('error', 'Sign in to view this topic.');
                Response::redirect('/login');
            }

            Response::forbidden('You cannot access this topic.');
        }
    }
}
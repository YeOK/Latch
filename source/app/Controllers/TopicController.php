<?php

declare(strict_types=1);

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
        $posts = $this->app->enrichPostsWithAvatars(
            $this->app->posts()->listByTopic((int) $topic['id'], false, $viewerId, $isMod, $isTrashBoard),
        );
        foreach ($posts as $i => $post) {
            $posts[$i]['chronological_index'] = $i + 1;
        }
        $posts = $this->enrichPostsForEdit($posts, $topic, $user, $isMod);
        if ($isTrashBoard) {
            $posts = $this->enrichTrashArchivePosts($posts);
        }

        // Guest-only page cache when the board is publicly readable.
        $cacheOptions = null;
        if (
            !$this->app->auth()->check()
            && !$this->app->membersOnly()
            && BoardAcl::isPublicRead($board)
            && $postSort === PostListSort::OLDEST
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

        $posts = PostListSort::sortPosts($posts, $postSort);

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

        $this->app->render('topic/show.html.twig', [
            'topic' => $topic,
            'board' => $board,
            'posts' => $posts,
            'post_sort' => $postSort,
            'post_sort_options' => PostListSort::labels(),
            'tags' => $tags,
            'can_edit_tags' => $can_edit_tags && empty($topic['deleted_at']),
            'is_watching' => $is_watching,
            'max_tags' => $this->app->maxTagsPerTopic(),
            'report_categories' => $this->app->reportReasons()->categories(),
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
            $posts[$i]['is_trashed_archive'] = ($post['trashed_at'] ?? null) !== null;
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

        if ($this->app->rateLimiter()->tooManyPosts((int) $user['id'], 10, 10)) {
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
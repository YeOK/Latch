<?php

declare(strict_types=1);

namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\BoardAcl;
use Latch\Core\Cache;
use Latch\Core\ModerationTrashService;
use Latch\Core\Response;
use Latch\Core\SeoMeta;
use Latch\Support\TopicListSort;
use RuntimeException;

final class BoardController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function show(array $params): void
    {
        $board = $this->findBoard($params['slug'] ?? '');
        $this->guardRead($board);

        $page = max(1, (int) $this->app->request()->input('page', 1));
        $perPage = (int) $this->app->config()->get('forum.topics_per_page', 30);
        $tagSlug = trim((string) $this->app->request()->input('tag', ''));
        $filterTag = null;
        if ($tagSlug !== '') {
            $filterTag = $this->app->tags()->findBySlug($tagSlug);
            if ($filterTag === null) {
                Response::notFound('Tag not found');
            }
            $tagSlug = (string) $filterTag['slug'];
        }

        $sort = TopicListSort::normalize($this->app->request()->input('sort'));
        $isMod = $this->app->auth()->isMod();
        $topics = $this->app->topics()->listByBoard(
            (int) $board['id'],
            $page,
            $perPage,
            $tagSlug ?: null,
            $isMod,
            $sort,
        );
        $total = $this->app->topics()->countByBoard((int) $board['id'], $tagSlug ?: null, $isMod);
        $topicIds = array_map(static fn (array $t): int => (int) $t['id'], $topics);
        $tagsByTopic = $this->app->tags()->forTopics($topicIds);
        foreach ($topics as &$topic) {
            $topic['tags'] = $tagsByTopic[(int) $topic['id']] ?? [];
        }
        unset($topic);

        $boardUser = $this->app->auth()->user();
        $viewerId = $boardUser !== null ? (int) $boardUser['id'] : null;
        $topics = $this->app->enrichTopicsWithUnread(
            $this->app->enrichTopicsWithAvatars($topics),
            $viewerId,
        );
        if ($sort === TopicListSort::UNREAD) {
            $topics = $this->sortTopicsUnreadFirst($topics);
        }

        // Guest-only page cache for public boards (default sort, no tag filter).
        $cacheOptions = null;
        if (
            !$this->app->auth()->check()
            && !$this->app->membersOnly()
            && BoardAcl::isPublicRead($board)
            && $tagSlug === ''
            && $sort === TopicListSort::ACTIVITY
        ) {
            $cacheOptions = [
                'route' => '/board/' . $board['slug'],
                'params' => ['page' => $page],
                'tags' => [Cache::tagBoard((int) $board['id']), Cache::tagSite()],
            ];
        }

        $loggedIn = $this->app->auth()->check();
        $viewerRole = $this->app->viewerRole();
        $membersOnly = $this->app->membersOnly();

        $canonicalPath = '/board/' . $board['slug'];
        $query = [];
        if ($tagSlug !== '') {
            $query['tag'] = $tagSlug;
        }
        if ($page > 1) {
            $query['page'] = (string) $page;
        }
        if ($sort !== TopicListSort::ACTIVITY) {
            $query['sort'] = $sort;
        }
        if ($query !== []) {
            $canonicalPath .= '?' . http_build_query($query);
        }

        $this->app->render('board/show.html.twig', [
            'board' => $board,
            'topics' => $topics,
            'page' => $page,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
            'topic_sort' => $sort,
            'topic_sort_options' => TopicListSort::labels(),
            'filter_tag' => $filterTag,
            'can_create_topic' => $this->app->boards()->canCreateTopic(
                $board,
                $loggedIn,
                $viewerRole,
                $membersOnly,
                $this->app->viewerReputationRank(),
            ),
            'can_reply' => $this->app->boards()->canReply(
                $board,
                $loggedIn,
                $viewerRole,
                $membersOnly,
                $this->app->viewerReputationRank(),
            ),
            'seo' => SeoMeta::forBoard(
                $this->app->siteUrl(),
                $this->app->siteName(),
                $board,
                $canonicalPath,
                $membersOnly,
            )->toArray(),
        ], $cacheOptions);
    }

    public function showNewTopic(array $params): void
    {
        $this->app->auth()->requireLogin();

        $board = $this->findBoard($params['slug'] ?? '');
        $this->guardRead($board);
        $this->guardCreateTopic($board);

        $this->app->render('board/new_topic.html.twig', [
            'board' => $board,
            'max_tags' => $this->app->maxTagsPerTopic(),
        ]);
    }

    public function createTopic(array $params): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            $this->app->session()->flash('error', 'Invalid form token.');
            Response::redirect('/board/' . ($params['slug'] ?? ''));
        }

        $board = $this->findBoard($params['slug'] ?? '');
        $this->guardRead($board);
        $this->guardCreateTopic($board);

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        if ($this->app->rateLimiter()->tooManyPosts((int) $user['id'], 10, 10)) {
            $this->app->session()->flash('error', 'You are posting too quickly. Wait a few minutes.');
            Response::redirect('/board/' . $board['slug'] . '/new');
        }

        if ($this->app->spamGuard()->honeypotTriggered()) {
            $this->app->spamGuard()->logHoneypot((int) $user['id']);
            $this->app->session()->flash('success', 'Topic posted.');
            Response::redirect('/board/' . $board['slug']);
        }

        $title = trim((string) $this->app->request()->input('title', ''));
        $body = trim((string) $this->app->request()->input('body', ''));

        $validator = $this->app->inputValidator();
        foreach ([
            $validator->topicTitleError($title),
            $validator->postBodyError($body),
        ] as $error) {
            if ($error !== null) {
                $this->app->session()->flash('error', $error);
                Response::redirect('/board/' . $board['slug'] . '/new');
            }
        }

        $linkError = $this->app->spamGuard()->linkLimitError($body, $user);
        if ($linkError !== null) {
            $this->app->session()->flash('error', $linkError);
            Response::redirect('/board/' . $board['slug'] . '/new');
        }

        $approvalStatus = $this->app->spamGuard()->approvalStatusForUser($user);

        $saveContext = new \Latch\Core\Plugins\PostSaveContext(
            $body,
            $user,
            $board,
            null,
            'topic',
            null,
            $title,
        );
        $rejectReason = $this->app->applyPostBeforeSave($saveContext);
        if ($rejectReason !== null) {
            $this->app->session()->flash('error', $rejectReason);
            Response::redirect('/board/' . $board['slug'] . '/new');
        }
        $body = $saveContext->body;

        try {
            $topic = $this->app->topics()->create(
                (int) $board['id'],
                (int) $user['id'],
                $title,
                $body,
                $approvalStatus,
            );
            $tagNames = $this->app->topicTags()->parse(
                (string) $this->app->request()->input('tags', ''),
                $this->app->maxTagsPerTopic(),
            );
            $this->app->tags()->syncForTopic((int) $topic['id'], $tagNames);
            $posts = $this->app->posts()->listByTopic((int) $topic['id'], false, (int) $user['id'], false);
            if ($approvalStatus === \Latch\Models\PostRepository::APPROVAL_APPROVED) {
                $this->app->indexSearchTopic((int) $topic['id']);
                if ($posts !== []) {
                    $this->app->notificationService()->onReply($topic, $posts[0], $user);
                    $this->app->participateInTopic((int) $user['id'], (int) $topic['id'], $posts);
                }
                $this->app->enqueueReputationUpdate((int) $user['id']);
            } else {
                $this->app->topicWatches()->watch((int) $user['id'], (int) $topic['id']);
                if ($posts !== []) {
                    $this->app->notificationService()->onPostPendingApproval($topic, $posts[0], $user, true);
                }
            }

            if ($posts !== []) {
                $saveContext->post = $posts[0];
                $saveContext->topic = $topic;
                $this->app->firePostAfterSave($saveContext);
            }
        } catch (RuntimeException $e) {
            $this->app->session()->flash('error', $e->getMessage());
            Response::redirect('/board/' . $board['slug'] . '/new');
        }

        $this->app->invalidateCacheTags([
            Cache::tagBoard((int) $board['id']),
            Cache::tagUser((int) $user['id']),
            Cache::tagSite(),
        ]);

        if ($approvalStatus === \Latch\Models\PostRepository::APPROVAL_PENDING) {
            $this->app->session()->flash('success', 'Your topic was submitted and is awaiting staff approval.');
            Response::redirect('/board/' . $board['slug']);
        }

        Response::redirect('/topic/' . $topic['id']);
    }

    /**
     * @param list<array<string, mixed>> $topics
     * @return list<array<string, mixed>>
     */
    private function sortTopicsUnreadFirst(array $topics): array
    {
        usort($topics, static function (array $a, array $b): int {
            $aUnread = !empty($a['is_unread']);
            $bUnread = !empty($b['is_unread']);
            if ($aUnread !== $bUnread) {
                return $bUnread <=> $aUnread;
            }

            $aPinned = !empty($a['is_pinned']);
            $bPinned = !empty($b['is_pinned']);
            if ($aPinned !== $bPinned) {
                return $bPinned <=> $aPinned;
            }

            return strcmp((string) ($b['last_post_at'] ?? ''), (string) ($a['last_post_at'] ?? ''));
        });

        return $topics;
    }

    private function findBoard(string $slug): array
    {
        if ($slug === ModerationTrashService::BOARD_SLUG) {
            return $this->app->moderationTrash()->trashBoard()
                ?? $this->app->moderationTrash()->ensureTrashBoard();
        }

        $board = $this->app->boards()->findBySlug($slug);
        if ($board === null) {
            Response::notFound('Board not found');
        }

        return $board;
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
                $this->app->session()->flash('error', 'Sign in to view this board.');
                Response::redirect('/login');
            }

            Response::forbidden('You cannot access this board.');
        }
    }

    private function guardCreateTopic(array $board): void
    {
        if ($this->app->boards()->canCreateTopic(
            $board,
            true,
            $this->app->viewerRole(),
            $this->app->membersOnly(),
            $this->app->viewerReputationRank(),
        )) {
            return;
        }

        $this->app->session()->flash('error', 'You cannot start new topics in this board.');
        Response::redirect('/board/' . $board['slug']);
    }
}
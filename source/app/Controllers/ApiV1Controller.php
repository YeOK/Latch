<?php

declare(strict_types=1);

namespace Latch\Controllers;

use Latch\Core\ApiContext;
use Latch\Core\ApiResponse;
use Latch\Core\ApiSerializer;
use Latch\Core\Application;
use Latch\Core\OAuthScopes;

final class ApiV1Controller
{
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    private readonly ApiSerializer $serializer;

    public function __construct(private readonly Application $app)
    {
        $this->serializer = new ApiSerializer();
    }

    public function meta(array $params = []): void
    {
        $ctx = $this->begin('GET', '/api/v1');
        ApiResponse::data([
            'name' => 'Latch API',
            'version' => 'v1',
            'authenticated' => $ctx->isLoggedIn(),
            'client_id' => $ctx->clientId,
            'scopes' => $ctx->scopes,
            'available_scopes' => OAuthScopes::ALL,
            'documentation' => 'source/docs/API.md',
        ]);
    }

    public function boards(array $params = []): void
    {
        $ctx = $this->begin('GET', '/api/v1/boards');
        $membersOnly = $this->app->membersOnly();
        $loggedIn = $ctx->isLoggedIn();
        $role = $ctx->userRole();

        $visible = array_values(array_filter(
            $this->app->boards()->all(),
            fn (array $board): bool => $this->app->boards()->canRead(
                $board,
                $loggedIn,
                $membersOnly,
                $role,
                $this->app->viewerReputationRank(),
            ),
        ));

        $data = array_map(
            fn (array $board): array => $this->serializer->board(
                $this->app->enrichBoardWithIcon($board),
            ),
            $visible,
        );

        ApiResponse::data($data, 200, ['count' => count($data)]);
    }

    public function board(array $params): void
    {
        $slug = trim((string) ($params['slug'] ?? ''));
        $ctx = $this->begin('GET', '/api/v1/boards/' . $slug);

        $board = $this->app->boards()->findBySlug($slug);
        if ($board === null) {
            ApiResponse::error('not_found', 'Board not found.', 404);
        }

        $this->guardBoardRead($board, $ctx);

        ApiResponse::data($this->serializer->board($this->app->enrichBoardWithIcon($board)));
    }

    public function boardTopics(array $params): void
    {
        $slug = trim((string) ($params['slug'] ?? ''));
        $ctx = $this->begin('GET', '/api/v1/boards/' . $slug . '/topics');

        $board = $this->app->boards()->findBySlug($slug);
        if ($board === null) {
            ApiResponse::error('not_found', 'Board not found.', 404);
        }

        $this->guardBoardRead($board, $ctx);

        $page = max(1, (int) $this->app->request()->input('page', 1));
        $perPage = $this->perPage();
        $isMod = $ctx->isMod();
        $boardId = (int) $board['id'];
        $topics = $this->app->topics()->listByBoard($boardId, $page, $perPage, null, $isMod);
        $total = $this->app->topics()->countByBoard($boardId, null, $isMod);

        $data = array_map(fn (array $topic): array => $this->serializer->topic($topic), $topics);

        ApiResponse::data($data, 200, [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'board' => (string) $board['slug'],
        ]);
    }

    public function topic(array $params): void
    {
        $topicId = (int) ($params['id'] ?? 0);
        $ctx = $this->begin('GET', '/api/v1/topics/' . $topicId);

        $topic = $this->app->topics()->findByIdWithAuthor($topicId, $ctx->isMod());
        if ($topic === null) {
            ApiResponse::error('not_found', 'Topic not found.', 404);
        }

        $board = $this->app->boards()->findById((int) $topic['board_id']);
        if ($board === null) {
            ApiResponse::error('not_found', 'Board not found.', 404);
        }

        $this->guardBoardRead($board, $ctx);
        $this->guardTopicVisible($topic, $ctx);

        ApiResponse::data($this->serializer->topic($topic));
    }

    public function topicPosts(array $params): void
    {
        $topicId = (int) ($params['id'] ?? 0);
        $ctx = $this->begin('GET', '/api/v1/topics/' . $topicId . '/posts');

        $topic = $this->app->topics()->findById($topicId);
        if ($topic === null) {
            ApiResponse::error('not_found', 'Topic not found.', 404);
        }

        $board = $this->app->boards()->findById((int) $topic['board_id']);
        if ($board === null) {
            ApiResponse::error('not_found', 'Board not found.', 404);
        }

        $this->guardBoardRead($board, $ctx);
        $this->guardTopicVisible($topic, $ctx);

        $posts = $this->app->posts()->listByTopic(
            $topicId,
            false,
            $ctx->userId(),
            $ctx->isMod(),
        );

        $data = [];
        foreach ($posts as $post) {
            $includeBody = $ctx->isMod() || ($post['quarantined_at'] ?? null) === null;
            $data[] = $this->serializer->post($post, $includeBody);
        }

        ApiResponse::data($data, 200, [
            'topic_id' => $topicId,
            'count' => count($data),
        ]);
    }

    public function user(array $params): void
    {
        $username = trim((string) ($params['username'] ?? ''));
        $ctx = $this->begin('GET', '/api/v1/users/' . $username);

        if ($this->app->membersOnly() && !$ctx->isLoggedIn()) {
            ApiResponse::error('unauthorized', 'Sign in required to view profiles.', 401);
        }

        if ($username === '') {
            ApiResponse::error('not_found', 'User not found.', 404);
        }

        $user = $this->app->users()->findByUsername($username);
        if ($user === null || $this->app->users()->isAnonymised($user)) {
            ApiResponse::error('not_found', 'User not found.', 404);
        }

        $stats = $this->app->users()->profileStats(
            (int) $user['id'],
            $ctx->isLoggedIn(),
            $ctx->isMod(),
            $ctx->userRole(),
        );
        $avatarSrc = $this->app->resolveAvatar(
            (string) $user['email'],
            96,
        );

        ApiResponse::data($this->serializer->user($user, $stats, $avatarSrc));
    }

    private function begin(string $method, string $path): ApiContext
    {
        $ctx = $this->app->apiAuth()->resolve();
        if (!$ctx->hasScope(OAuthScopes::READ)) {
            ApiResponse::error('insufficient_scope', 'The read scope is required.', 403);
        }

        if ($ctx->clientId === null) {
            $this->app->apiAuth()->enforceGuestRateLimit();
        }

        register_shutdown_function(function () use ($method, $path, $ctx): void {
            $status = http_response_code();
            if (!is_int($status) || $status === false) {
                $status = 200;
            }
            $this->app->apiAuditLog()->record(
                $ctx->clientId,
                $ctx->userId(),
                $method,
                $path,
                $status,
                $this->app->request()->ip(),
            );
        });

        return $ctx;
    }

    /**
     * @param array<string, mixed> $board
     */
    private function guardBoardRead(array $board, ApiContext $ctx): void
    {
        if (!$this->app->boards()->canRead(
            $board,
            $ctx->isLoggedIn(),
            $this->app->membersOnly(),
            $ctx->userRole(),
            $this->app->viewerReputationRank(),
        )) {
            ApiResponse::error('forbidden', 'You cannot access this board.', 403);
        }
    }

    /**
     * @param array<string, mixed> $topic
     */
    private function guardTopicVisible(array $topic, ApiContext $ctx): void
    {
        if (!empty($topic['deleted_at']) && !$ctx->isMod()) {
            ApiResponse::error('not_found', 'Topic not found.', 404);
        }

        if (
            !$ctx->isMod()
            && !$this->app->posts()->topicHasApprovedPost((int) $topic['id'])
            && ($ctx->userId() === null || $ctx->userId() !== (int) $topic['user_id'])
        ) {
            ApiResponse::error('not_found', 'Topic not found.', 404);
        }
    }

    private function perPage(): int
    {
        $perPage = (int) $this->app->request()->input('per_page', self::DEFAULT_PER_PAGE);

        return max(1, min(self::MAX_PER_PAGE, $perPage));
    }
}
<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\Response;

final class TopicWatchController
{
    private const PER_PAGE = 30;

    public function __construct(private readonly Application $app)
    {
    }

    public function index(array $params = []): void
    {
        $this->app->auth()->requireLogin();
        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $userId = (int) $user['id'];
        $page = max(1, (int) $this->app->request()->input('page', 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $topics = $this->app->topicWatches()->listWatchedTopics($userId, self::PER_PAGE, $offset);
        $topics = $this->app->enrichTopicsWithAvatars($topics);
        $topicIds = array_map(static fn (array $t): int => (int) $t['id'], $topics);
        $tagsByTopic = $this->app->tags()->forTopics($topicIds);
        foreach ($topics as &$topic) {
            $topic['tags'] = $tagsByTopic[(int) $topic['id']] ?? [];
            $topic['is_unread'] = !empty($topic['is_unread']);
        }
        unset($topic);

        $total = $this->app->topicWatches()->countWatched($userId);

        $this->app->render('watched/index.html.twig', [
            'topics' => $topics,
            'unread_count' => $this->app->topicWatches()->countUnreadWatched($userId),
            'page' => $page,
            'total_pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
        ]);
    }

    public function toggle(array $params): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::json(['ok' => false, 'message' => 'Invalid form token.'], 403);
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::json(['ok' => false, 'message' => 'Sign in to watch topics.'], 401);
        }

        $topicId = (int) ($params['id'] ?? 0);
        $topic = $this->app->topics()->findById($topicId);
        if ($topic === null || !empty($topic['deleted_at'])) {
            Response::json(['ok' => false, 'message' => 'Topic not found.'], 404);
        }

        $board = $this->app->boards()->findById((int) $topic['board_id']);
        if ($board === null || !$this->app->boards()->canRead(
            $board,
            true,
            $this->app->membersOnly(),
            $this->app->viewerRole(),
            $this->app->viewerReputationRank(),
        )) {
            Response::json(['ok' => false, 'message' => 'You cannot access this topic.'], 403);
        }

        $watching = $this->app->topicWatches()->toggleWatch((int) $user['id'], $topicId);

        Response::json([
            'ok' => true,
            'watching' => $watching,
            'watched_unread' => $this->app->topicWatches()->countUnreadWatched((int) $user['id']),
        ]);
    }
}
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
use Latch\Models\PostReactionRepository;
use Latch\Models\PostRepository;
use RuntimeException;

final class PostVoteController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function vote(array $params): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::json(['ok' => false, 'message' => 'Invalid form token.'], 403);
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::json(['ok' => false, 'message' => 'Sign in to vote.'], 401);
        }

        $userId = (int) $user['id'];
        if ($this->app->postReactions()->countRecentChanges($userId, 10) >= 60) {
            $this->app->securityLog()->log('vote_rate_limit', [
                'ip' => $this->app->request()->ip(),
                'user_id' => $userId,
            ]);
            Response::json(['ok' => false, 'message' => 'Too many vote changes. Wait a few minutes.'], 429);
        }

        $postId = (int) ($params['id'] ?? 0);
        $rawVote = strtolower(trim((string) $this->app->request()->input('vote', '')));
        $vote = match ($rawVote) {
            'like' => PostReactionRepository::VOTE_LIKE,
            'dislike' => PostReactionRepository::VOTE_DISLIKE,
            'clear' => null,
            default => '__invalid__',
        };

        if ($vote === '__invalid__') {
            Response::json(['ok' => false, 'message' => 'Invalid vote.'], 400);
        }

        $post = $this->app->posts()->findById($postId);
        if ($post === null) {
            Response::json(['ok' => false, 'message' => 'Post not found.'], 404);
        }

        if (!$this->app->canUserAccessPost($post)) {
            Response::json(['ok' => false, 'message' => 'You cannot access this post.'], 403);
        }

        try {
            $result = $this->app->postReactions()->setVote($postId, $userId, $vote);
        } catch (RuntimeException $e) {
            Response::json(['ok' => false, 'message' => $e->getMessage()], 400);
        }

        if ($result['became_like']) {
            $topic = $this->app->topics()->findById((int) $post['topic_id']);
            if ($topic !== null) {
                $this->app->notificationService()->onPostLiked($post, $topic, $user);
            }
        }

        $this->app->enqueueReputationUpdate((int) $post['user_id']);

        Response::json([
            'ok' => true,
            'like_count' => $result['like_count'],
            'dislike_count' => $result['dislike_count'],
            'viewer_vote' => $result['viewer_vote'],
        ]);
    }
}
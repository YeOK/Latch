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

final class UserFollowController
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
        $people = $this->app->userFollows()->listFollowing($userId, self::PER_PAGE, $offset);
        foreach ($people as $i => $row) {
            $people[$i]['avatar_src'] = $this->app->resolveAvatar((string) ($row['email'] ?? ''), 48);
            $people[$i]['avatar_hue'] = $this->app->avatarHue((string) ($row['username'] ?? ''));
        }

        $total = $this->app->userFollows()->followingCount($userId);

        $this->app->render('following/index.html.twig', [
            'people' => $people,
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

        $viewer = $this->app->auth()->user();
        if ($viewer === null) {
            Response::json(['ok' => false, 'message' => 'Sign in to follow members.'], 401);
        }

        $targetId = (int) ($params['id'] ?? 0);
        $result = $this->app->userFollows()->toggle((int) $viewer['id'], $targetId);
        if (empty($result['ok'])) {
            Response::json([
                'ok' => false,
                'message' => (string) ($result['error'] ?? 'Could not update follow.'),
            ], 400);
        }

        Response::json([
            'ok' => true,
            'following' => !empty($result['following']),
            'follower_count' => $this->app->userFollows()->followerCount($targetId),
        ]);
    }
}

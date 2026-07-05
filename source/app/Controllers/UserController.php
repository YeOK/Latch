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
use Latch\Core\Response;
use Latch\Core\SeoMeta;

final class UserController
{
    private const RECENT_POSTS_LIMIT = 20;

    public function __construct(private readonly Application $app)
    {
    }

    public function show(array $params): void
    {
        if ($this->app->membersOnly() && !$this->app->auth()->check()) {
            $this->app->session()->flash('error', 'Sign in to view member profiles.');
            Response::redirect('/login');
        }

        $username = trim((string) ($params['username'] ?? ''));
        if ($username === '') {
            Response::notFound('User not found');
        }

        $user = $this->app->users()->findByUsername($username);
        if ($user === null || $this->app->users()->isAnonymised($user)) {
            Response::notFound('User not found');
        }

        $loggedIn = $this->app->auth()->check();
        $isMod = $this->app->auth()->isMod();
        $userId = (int) $user['id'];
        $viewerRole = $this->app->viewerRole();
        $stats = $this->app->users()->profileStats($userId, $loggedIn, $isMod, $viewerRole);
        $recentPosts = $this->app->posts()->recentPublicByUser(
            $userId,
            self::RECENT_POSTS_LIMIT,
            $loggedIn,
            $isMod,
            $viewerRole,
        );

        foreach ($recentPosts as $i => $post) {
            $recentPosts[$i]['excerpt'] = $this->app->rss()->plainExcerpt((string) $post['body'], 200);
            unset($recentPosts[$i]['body']);
        }

        $role = (string) $user['role'];
        $reputation = $this->app->reputation()->profileViewForUser($user);
        $viewer = $this->app->auth()->user();
        $canMessage = false;
        if ($viewer !== null && (int) $viewer['id'] !== $userId) {
            $canMessage = $this->app->messages()->canStartWith($viewer, $userId);
        }

        $this->app->render('user/show.html.twig', [
            'profile' => [
                'username' => (string) $user['username'],
                'bio' => (string) ($user['bio'] ?? ''),
                'created_at' => (string) $user['created_at'],
                'role' => $role,
                'is_staff' => in_array($role, ['admin', 'mod'], true),
                'staff_label' => match ($role) {
                    'admin' => 'Admin',
                    'mod' => 'Moderator',
                    default => null,
                },
                'avatar_src' => $this->app->resolveAvatar(
                    (string) $user['email'],
                    96,
                ),
                'avatar_hue' => $this->app->avatarHue((string) $user['username']),
                'post_count' => $stats['post_count'],
                'topic_count' => $stats['topic_count'],
                'reputation' => $reputation,
            ],
            'recent_posts' => $recentPosts,
            'is_own_profile' => $viewer !== null && (int) $viewer['id'] === $userId,
            'can_message' => $canMessage,
            'seo' => SeoMeta::forUser(
                $this->app->siteUrl(),
                $this->app->siteName(),
                (string) $user['username'],
                (string) ($user['bio'] ?? ''),
                $this->app->membersOnly(),
            )->toArray(),
        ], [
            'route' => '/user/' . $user['username'],
            'tags' => [Cache::tagUser($userId), Cache::tagSite()],
        ]);
    }
}
<?php

declare(strict_types=1);

namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\Cache;
use Latch\Core\SeoMeta;

final class HomeController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function index(array $params = []): void
    {
        $boards = $this->app->boards()->all();
        $loggedIn = $this->app->auth()->check();
        $membersOnly = $this->app->membersOnly();

        $visible = array_values(array_filter(
            $boards,
            fn (array $board): bool => $this->app->boards()->canRead(
                $board,
                $loggedIn,
                $membersOnly,
                $this->app->viewerRole(),
                $this->app->viewerReputationRank(),
            )
        ));
        $visible = array_map(
            fn (array $board): array => $this->app->enrichBoardWithIcon($board),
            $visible,
        );
        $viewerId = $loggedIn ? (int) ($this->app->auth()->user()['id'] ?? 0) : null;
        $isMod = $this->app->auth()->isMod();
        $visible = $this->app->enrichBoardsWithUnread($visible, $viewerId);
        $visible = $this->app->enrichBoardsForHome($visible, $isMod, $viewerId);

        $this->app->render('home/index.html.twig', [
            'boards' => $visible,
            'members_only' => $membersOnly,
            'seo' => SeoMeta::forHome(
                $this->app->siteUrl(),
                $this->app->siteName(),
                $this->app->siteTagline(),
                $membersOnly,
            )->toArray(),
        ], [
            'route' => '/',
            'tags' => [Cache::tagSite()],
        ]);
    }
}
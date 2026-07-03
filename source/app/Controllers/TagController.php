<?php

declare(strict_types=1);

namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\Response;
use Latch\Core\SeoMeta;

final class TagController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function show(array $params): void
    {
        $slug = trim((string) ($params['slug'] ?? ''));
        $tag = $this->app->tags()->findBySlug($slug);
        if ($tag === null) {
            Response::notFound('Tag not found');
        }

        if ($this->app->membersOnly() && !$this->app->auth()->check()) {
            $this->app->session()->flash('error', 'Sign in to browse topics.');
            Response::redirect('/login');
        }

        $page = max(1, (int) $this->app->request()->input('page', 1));
        $perPage = (int) $this->app->config()->get('forum.topics_per_page', 30);
        $isMod = $this->app->auth()->isMod();
        $topics = $this->app->tags()->listTopicsByTag($slug, $page, $perPage, $isMod);
        $total = $this->app->tags()->countTopics($slug, $isMod);
        $topicIds = array_map(static fn (array $t): int => (int) $t['id'], $topics);
        $tagsByTopic = $this->app->tags()->forTopics($topicIds);

        foreach ($topics as &$topic) {
            $topic['tags'] = $tagsByTopic[(int) $topic['id']] ?? [];
        }
        unset($topic);

        $topics = $this->app->enrichTopicsWithAvatars($topics);

        $canonicalPath = '/tag/' . $tag['slug'];
        if ($page > 1) {
            $canonicalPath .= '?page=' . $page;
        }

        $this->app->render('tag/show.html.twig', [
            'tag' => $tag,
            'topics' => $topics,
            'page' => $page,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
            'seo' => SeoMeta::forTag(
                $this->app->siteUrl(),
                $this->app->siteName(),
                (string) $tag['name'],
                $canonicalPath,
                $this->app->membersOnly(),
            )->toArray(),
        ]);
    }

    public function suggest(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        $query = trim((string) $this->app->request()->input('q', ''));
        $queryError = $this->app->inputValidator()->searchQueryError($query);
        if ($queryError !== null) {
            Response::json(['tags' => [], 'error' => $queryError], 400);
        }

        $tags = $this->app->tags()->suggest($query);

        Response::json([
            'tags' => array_map(static fn (array $t): array => [
                'name' => $t['name'],
                'slug' => $t['slug'],
            ], $tags),
        ]);
    }
}
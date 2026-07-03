<?php

declare(strict_types=1);

namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\Cache;
use Latch\Core\Response;
use Latch\Core\RssFeed;

final class RssController
{
    private const DEFAULT_LIMIT = 50;

    public function __construct(private readonly Application $app)
    {
    }

    public function siteFeed(array $params = []): void
    {
        if ($this->app->membersOnly() && !$this->app->auth()->check()) {
            Response::forbidden('RSS is available to signed-in members only.');
        }

        $limit = $this->feedLimit();
        $cacheRoute = '/feed.xml';
        $xml = $this->cachedFeed($cacheRoute, [], function () use ($limit): string {
            $siteName = $this->siteName();
            $siteUrl = $this->siteUrl();
            $topics = $this->app->rss()->recentTopicsForSite(
                $limit,
                $this->app->auth()->check(),
                $this->app->membersOnly(),
                $this->app->viewerRole(),
            );
            $topicIds = array_map(static fn (array $row): int => (int) $row['id'], $topics);
            $tagsByTopic = $this->app->tags()->forTopics($topicIds);

            $feed = new RssFeed(
                $siteName . ' — All boards',
                $siteUrl . '/',
                'Recent topics across ' . $siteName,
                $siteUrl . '/feed.xml',
            );

            foreach ($topics as $topic) {
                $this->addTopicItem($feed, $topic, $tagsByTopic[(int) $topic['id']] ?? [], $siteUrl);
            }

            return $feed->render();
        });

        Response::xml($xml, cacheable: $this->canCacheFeeds());
    }

    public function boardFeed(array $params): void
    {
        $board = $this->app->boards()->findBySlug((string) ($params['slug'] ?? ''));
        if ($board === null) {
            Response::notFound('Board not found');
        }

        if (!$this->app->boards()->canRead(
            $board,
            $this->app->auth()->check(),
            $this->app->membersOnly(),
            $this->app->viewerRole(),
            $this->app->viewerReputationRank(),
        )) {
            Response::forbidden('This board feed is not available.');
        }

        $limit = $this->feedLimit();
        $cacheRoute = '/board/' . $board['slug'] . '/feed.xml';

        $xml = $this->cachedFeed($cacheRoute, [], function () use ($board, $limit): string {
            $siteUrl = $this->siteUrl();
            $topics = $this->app->rss()->recentTopicsForBoard((int) $board['id'], $limit);
            $topicIds = array_map(static fn (array $row): int => (int) $row['id'], $topics);
            $tagsByTopic = $this->app->tags()->forTopics($topicIds);

            $feed = new RssFeed(
                (string) $board['name'] . ' — ' . $this->siteName(),
                $siteUrl . '/board/' . $board['slug'],
                (string) ($board['description'] !== '' ? $board['description'] : 'Topics in ' . $board['name']),
                $siteUrl . '/board/' . $board['slug'] . '/feed.xml',
            );

            foreach ($topics as $topic) {
                $this->addTopicItem(
                    $feed,
                    $topic,
                    $tagsByTopic[(int) $topic['id']] ?? [],
                    $siteUrl,
                );
            }

            return $feed->render();
        });

        Response::xml($xml, cacheable: $this->canCacheFeeds());
    }

    public function topicFeed(array $params): void
    {
        $topic = $this->app->topics()->findById((int) ($params['id'] ?? 0));
        if ($topic === null || (!empty($topic['deleted_at']) && !$this->app->auth()->isMod())) {
            Response::notFound('Topic not found');
        }

        $board = $this->app->boards()->findById((int) $topic['board_id']);
        if ($board === null) {
            Response::notFound('Board not found');
        }

        if (!$this->app->boards()->canRead(
            $board,
            $this->app->auth()->check(),
            $this->app->membersOnly(),
            $this->app->viewerRole(),
            $this->app->viewerReputationRank(),
        )) {
            Response::forbidden('This topic feed is not available.');
        }

        $cacheRoute = '/topic/' . $topic['id'] . '/feed.xml';
        $xml = $this->cachedFeed($cacheRoute, [], function () use ($topic, $board): string {
            $siteUrl = $this->siteUrl();
            $posts = $this->app->rss()->postsForTopic((int) $topic['id'], $this->app->auth()->isMod());
            $tags = array_map(
                static fn (array $tag): string => (string) $tag['name'],
                $this->app->tags()->forTopic((int) $topic['id']),
            );

            $feed = new RssFeed(
                (string) $topic['title'] . ' — ' . $this->siteName(),
                $siteUrl . '/topic/' . $topic['id'],
                'Replies in ' . $topic['title'] . ' (' . $board['name'] . ')',
                $siteUrl . '/topic/' . $topic['id'] . '/feed.xml',
            );

            $isFirst = true;
            foreach ($posts as $post) {
                $title = $isFirst
                    ? (string) $topic['title']
                    : 'Re: ' . $topic['title'];
                $link = $siteUrl . '/topic/' . $topic['id'] . '#post-' . $post['id'];
                $description = $this->app->rss()->plainExcerpt((string) $post['body']);
                if ($post['quarantined_at'] !== null && $this->app->auth()->isMod()) {
                    $description = '[Quarantined] ' . $description;
                }

                $feed->addItem(
                    $title,
                    $link,
                    $link,
                    (string) $post['created_at'],
                    $description,
                    (string) $post['author_name'],
                    $isFirst ? $tags : [],
                );
                $isFirst = false;
            }

            return $feed->render();
        });

        Response::xml($xml, cacheable: $this->canCacheFeeds());
    }

    /**
     * @param list<array<string, mixed>> $tags
     */
    private function addTopicItem(RssFeed $feed, array $topic, array $tags, string $siteUrl): void
    {
        $topicUrl = $siteUrl . '/topic/' . $topic['id'];
        $tagNames = array_map(static fn (array $tag): string => (string) $tag['name'], $tags);
        $description = $this->app->rss()->plainExcerpt((string) ($topic['first_post_body'] ?? ''));
        if ($tagNames !== []) {
            $description .= "\n\nTags: " . implode(', ', $tagNames);
        }

        $feed->addItem(
            (string) $topic['title'],
            $topicUrl,
            $topicUrl,
            (string) $topic['last_post_at'],
            $description,
            (string) $topic['author_name'],
            $tagNames,
        );
    }

    /**
     * @param array<string, scalar> $params
     */
    private function cachedFeed(string $route, array $params, callable $builder): string
    {
        if (!$this->canCacheFeeds()) {
            return $builder();
        }

        $cacheKey = Cache::makeKey($route, $params);
        $cached = $this->app->cache()->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $xml = $builder();
        $this->app->cache()->set($cacheKey, $xml, $this->app->cacheTtlSeconds(), [Cache::tagSite()]);

        return $xml;
    }

    private function canCacheFeeds(): bool
    {
        return $this->app->cacheEnabled()
            && !$this->app->auth()->check()
            && !$this->app->membersOnly();
    }

    private function feedLimit(): int
    {
        return max(1, min(100, (int) $this->app->config()->get('forum.rss_items_limit', self::DEFAULT_LIMIT)));
    }

    private function siteUrl(): string
    {
        return rtrim((string) $this->app->config()->get('site.url', 'http://localhost'), '/');
    }

    private function siteName(): string
    {
        return $this->app->settings()->get('site_name', (string) $this->app->config()->get('site.name', 'Latch'));
    }
}
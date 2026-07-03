<?php

declare(strict_types=1);

namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\Response;

final class SearchController
{
    private const MIN_QUERY_LENGTH = 2;
    private const RESULTS_PER_PAGE = 20;

    public function __construct(private readonly Application $app)
    {
    }

    public function index(array $params = []): void
    {
        if ($this->app->membersOnly() && !$this->app->auth()->check()) {
            $this->app->session()->flash('error', 'Sign in to search the forum.');
            Response::redirect('/login');
        }

        $query = trim((string) $this->app->request()->input('q', ''));
        $queryError = $this->app->inputValidator()->searchQueryError($query);
        if ($queryError !== null) {
            $this->app->session()->flash('error', $queryError);
            Response::redirect('/search');
        }

        $page = max(1, (int) $this->app->request()->input('page', 1));
        $results = [];
        $total = 0;
        $error = null;

        if ($query !== '') {
            if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
                $error = 'Enter at least ' . self::MIN_QUERY_LENGTH . ' characters to search.';
            } elseif (!$this->app->search()->isEnabled()) {
                $error = 'Search is not available yet. Ask an admin to run search reindex.';
            } else {
                $ip = $this->app->request()->ip();
                if ($this->app->rateLimiter()->tooManySearches($ip)) {
                    $error = 'Too many searches. Wait a minute and try again.';
                } else {
                    $this->app->rateLimiter()->recordSearchAttempt($ip);
                    $search = $this->app->search()->search(
                        $query,
                        $this->app->auth()->check(),
                        $this->app->membersOnly(),
                        $page,
                        self::RESULTS_PER_PAGE,
                        $this->app->viewerRole(),
                    );
                    $results = $search['results'];
                    $total = $search['total'];

                    $topicIds = array_map(static fn (array $r): int => (int) $r['topic_id'], $results);
                    $tagsByTopic = $this->app->tags()->forTopics($topicIds);
                    foreach ($results as &$row) {
                        $row['tags'] = $tagsByTopic[(int) $row['topic_id']] ?? [];
                    }
                    unset($row);
                }
            }
        }

        $this->app->render('search/show.html.twig', [
            'query' => $query,
            'search_query' => $query,
            'results' => $results,
            'page' => $page,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / self::RESULTS_PER_PAGE)),
            'search_error' => $error,
        ]);
    }
}
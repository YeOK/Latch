<?php

declare(strict_types=1);

namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\DateTimeFormatter;
use Latch\Core\Response;

final class NotificationController
{
    private const PER_PAGE = 30;
    private const FEED_LIMIT = 20;

    private readonly DateTimeFormatter $dates;

    public function __construct(private readonly Application $app)
    {
        $this->dates = new DateTimeFormatter();
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

        $items = $this->app->notifications()->listForUser($userId, self::PER_PAGE, $offset);
        $unread = $this->app->notifications()->countUnread($userId);

        $this->app->render('notifications/index.html.twig', [
            'notifications' => $items,
            'unread_count' => $unread,
            'page' => $page,
            'has_more' => count($items) === self::PER_PAGE,
        ]);
    }

    public function feed(array $params = []): void
    {
        $this->app->auth()->requireLogin();
        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::json(['ok' => false, 'message' => 'Sign in required.'], 401);
        }

        $userId = (int) $user['id'];
        $limit = max(1, min(self::FEED_LIMIT, (int) $this->app->request()->input('limit', self::FEED_LIMIT)));
        $items = $this->app->notifications()->listForUser($userId, $limit, 0);

        Response::json([
            'ok' => true,
            'unread_count' => $this->app->notifications()->countUnread($userId),
            'notifications' => array_map(fn (array $item): array => $this->serialize($item), $items),
            'has_more' => count($items) === $limit,
        ]);
    }

    public function go(array $params): void
    {
        $this->app->auth()->requireLogin();
        $user = $this->app->auth()->user();
        if ($user === null) {
            if ($this->wantsJson()) {
                Response::json(['ok' => false, 'message' => 'Sign in required.'], 401);
            }
            Response::redirect('/login');
        }

        $id = (int) ($params['id'] ?? 0);
        $notification = $this->app->notifications()->findByIdForUser($id, (int) $user['id']);
        if ($notification === null) {
            if ($this->wantsJson()) {
                Response::json(['ok' => false, 'message' => 'Notification not found.'], 404);
            }
            Response::notFound('Notification not found');
        }

        $this->app->notifications()->markRead($id, (int) $user['id']);

        $url = $this->safeUrl((string) ($notification['url'] ?? '/notifications'));

        if ($this->wantsJson()) {
            Response::json([
                'ok' => true,
                'url' => $url,
                'unread_count' => $this->app->notifications()->countUnread((int) $user['id']),
            ]);
        }

        Response::redirect($url);
    }

    public function markRead(array $params): void
    {
        $this->app->auth()->requireLogin();
        $this->validateCsrf();

        $user = $this->app->auth()->user();
        if ($user === null) {
            if ($this->wantsJson()) {
                Response::json(['ok' => false, 'message' => 'Sign in required.'], 401);
            }
            Response::redirect('/login');
        }

        $id = (int) ($params['id'] ?? 0);
        $this->app->notifications()->markRead($id, (int) $user['id']);

        if ($this->wantsJson()) {
            Response::json([
                'ok' => true,
                'unread_count' => $this->app->notifications()->countUnread((int) $user['id']),
            ]);
        }

        Response::redirect('/notifications');
    }

    public function markAllRead(array $params = []): void
    {
        $this->app->auth()->requireLogin();
        $this->validateCsrf();

        $user = $this->app->auth()->user();
        if ($user === null) {
            if ($this->wantsJson()) {
                Response::json(['ok' => false, 'message' => 'Sign in required.'], 401);
            }
            Response::redirect('/login');
        }

        $marked = $this->app->notifications()->markAllRead((int) $user['id']);

        if ($this->wantsJson()) {
            Response::json([
                'ok' => true,
                'marked' => $marked,
                'unread_count' => 0,
            ]);
        }

        $this->app->session()->flash('success', 'All notifications marked as read.');
        Response::redirect('/notifications');
    }

    private function validateCsrf(): void
    {
        if ($this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            return;
        }

        if ($this->wantsJson()) {
            Response::json(['ok' => false, 'message' => 'Invalid form token.'], 403);
        }

        $this->app->session()->flash('error', 'Invalid form token.');
        Response::redirect('/notifications');
    }

    private function wantsJson(): bool
    {
        return $this->app->request()->header('X-Requested-With') === 'XMLHttpRequest';
    }

    private function safeUrl(string $url): string
    {
        if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return '/notifications';
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function serialize(array $item): array
    {
        return [
            'id' => (int) $item['id'],
            'message' => (string) $item['message'],
            'url' => $this->safeUrl((string) ($item['url'] ?? '')),
            'event_type' => (string) ($item['event_type'] ?? ''),
            'created_at' => (string) ($item['created_at'] ?? ''),
            'created_at_label' => $this->dates->format(isset($item['created_at']) ? (string) $item['created_at'] : null),
            'is_unread' => !empty($item['is_unread']),
        ];
    }
}
<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use Latch\Models\NotificationRepository;

/**
 * Resolves stored notification rows to locale-aware display text (Phase 8 PHP i18n).
 *
 * English `message` columns are kept for email/audit; this formatter runs at read time
 * for the recipient's locale and falls back to the stored message when no template matches.
 */
final class NotificationMessageFormatter
{
    /** @var array<string, string> staff/meta action => translation key */
    private const STAFF_ACTION_KEYS = [
        'topic.lock' => 'notify.topic_locked',
        'topic.unlock' => 'notify.topic_unlocked',
        'topic.pin' => 'notify.topic_pinned',
        'topic.unpin' => 'notify.topic_unpinned',
        'topic.delete' => 'notify.topic_removed',
        'post.trash' => 'notify.post_removed_review',
        'post.approve' => 'notify.post_approved',
        'post.reject' => 'notify.post_rejected',
        'quarantine' => 'notify.post_quarantine',
    ];

    /**
     * @param array<string, mixed> $notification hydrated row from NotificationRepository
     */
    public function format(array $notification, Translator $translator): string
    {
        $fallback = (string) ($notification['message'] ?? '');
        $eventType = (string) ($notification['event_type'] ?? '');
        /** @var array<string, mixed> $meta */
        $meta = is_array($notification['meta'] ?? null) ? $notification['meta'] : [];
        $actor = $this->actorLabel($notification);
        $title = $this->resolveTitle($meta, $fallback);

        $key = match ($eventType) {
            NotificationRepository::TYPE_TOPIC_REPLY => 'notify.topic_reply',
            NotificationRepository::TYPE_POST_QUOTE => 'notify.post_quote',
            NotificationRepository::TYPE_POST_LIKE => 'notify.post_like',
            NotificationRepository::TYPE_MENTION => 'notify.mention',
            NotificationRepository::TYPE_USER_WARN => 'notify.user_warn',
            NotificationRepository::TYPE_POST_PENDING => !empty($meta['is_new_topic'])
                ? 'notify.post_pending_topic'
                : 'notify.post_pending_reply',
            NotificationRepository::TYPE_DIRECT_MESSAGE => null,
            NotificationRepository::TYPE_STAFF_ACTION => $this->staffActionKey($meta),
            default => null,
        };

        if ($key === null) {
            return $fallback;
        }

        $replace = match ($key) {
            'notify.user_warn' => ['reason' => (string) ($meta['reason'] ?? $this->parseWarnReason($fallback))],
            default => ['actor' => $actor, 'title' => $title],
        };

        $translated = $translator->get($key, $replace);

        return $translated !== $key ? $translated : $fallback;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function formatMany(array $items, Translator $translator): array
    {
        return array_map(
            function (array $item) use ($translator): array {
                $item['message'] = $this->format($item, $translator);

                return $item;
            },
            $items,
        );
    }

    /**
     * @param array<string, mixed> $notification
     */
    private function actorLabel(array $notification): string
    {
        $username = trim((string) ($notification['actor_username'] ?? ''));

        return $username !== '' ? '@' . $username : '@Staff';
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function resolveTitle(array $meta, string $fallback): string
    {
        $fromMeta = trim((string) ($meta['topic_title'] ?? ''));

        return $fromMeta !== '' ? $fromMeta : $this->parseQuotedTitle($fallback);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function staffActionKey(array $meta): ?string
    {
        $action = (string) ($meta['action'] ?? '');

        return self::STAFF_ACTION_KEYS[$action] ?? null;
    }

    private function parseQuotedTitle(string $message): string
    {
        if (preg_match('/"([^"]+)"/', $message, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    private function parseWarnReason(string $message): string
    {
        $prefix = 'Staff issued you a warning: ';

        return str_starts_with($message, $prefix) ? substr($message, strlen($prefix)) : $message;
    }
}
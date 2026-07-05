<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

use Latch\Core\Application;
use Latch\Core\BulkCacheCollector;
use Latch\Core\ModerationTrashBatch;
/**
 * Bulk pin/lock/delete with deferred cache and search side effects.
 */
final class BulkTopicActionService
{
    public function __construct(private readonly Application $app)
    {
    }

    /**
     * @param list<int>          $topicIds
     * @return array{
     *   ok: bool,
     *   message: string,
     *   processed: int,
     *   skipped: int,
     *   archived_posts: int
     * }
     */
    public function execute(string $action, array $topicIds, int $staffId): array
    {
        $processed = 0;
        $skipped = 0;
        $archivedPosts = 0;
        $cache = new BulkCacheCollector();
        $trashBatch = $action === 'delete' ? new ModerationTrashBatch() : null;
        $trashBoardTouched = false;

        foreach ($topicIds as $id) {
            $topic = $this->app->topics()->findById($id);
            if ($topic === null || !empty($topic['deleted_at'])) {
                $skipped++;
                continue;
            }

            $board = $this->app->boards()->findById((int) $topic['board_id']);
            if ($board !== null && $this->app->moderationTrash()->isTrashBoard($board)) {
                $skipped++;
                continue;
            }

            if ($this->shouldSkipUnchanged($action, $topic)) {
                $skipped++;
                continue;
            }

            if ($action === 'pin') {
                $this->app->topics()->setPinned($id, true);
                $this->recordAudit($staffId, 'topic.pin', $id);
            } elseif ($action === 'unpin') {
                $this->app->topics()->setPinned($id, false);
                $this->recordAudit($staffId, 'topic.unpin', $id);
            } elseif ($action === 'lock') {
                $this->app->topics()->setLocked($id, true);
                $this->recordAudit($staffId, 'topic.lock', $id);
            } elseif ($action === 'unlock') {
                $this->app->topics()->setLocked($id, false);
                $this->recordAudit($staffId, 'topic.unlock', $id);
            } else {
                $archived = $this->app->moderationTrash()->archiveTopic($id, $staffId, $trashBatch);
                $archivedPosts += $archived;
                $this->recordAudit($staffId, 'topic.delete', $id, [
                    'bulk' => true,
                    'archived_posts' => $archived,
                ]);
                $trashBoardTouched = true;
            }

            $cache->addTopic($topic);
            $processed++;
        }

        if ($trashBatch !== null) {
            $trashBatch->flush($this->app->search());
        }

        if ($trashBoardTouched) {
            $trashBoard = $this->app->moderationTrash()->trashBoard();
            if ($trashBoard !== null) {
                $cache->addBoard((int) $trashBoard['id']);
            }
        }

        $cache->flush($this->app);

        $labels = [
            'pin' => 'Pinned',
            'unpin' => 'Unpinned',
            'lock' => 'Locked',
            'unlock' => 'Unlocked',
            'delete' => 'Removed',
        ];
        $verb = $labels[$action] ?? 'Updated';
        $message = $processed > 0
            ? "{$verb} {$processed} topic(s)."
            : 'No topics were updated.';
        if ($skipped > 0) {
            $message .= " Skipped {$skipped}.";
        }
        if ($action === 'delete' && $archivedPosts > 0) {
            $trashBoard = $this->app->moderationTrash()->trashBoard();
            $trashName = (string) ($trashBoard['name'] ?? 'Moderation trash');
            $message .= " {$archivedPosts} post(s) moved to {$trashName}.";
        }

        return [
            'ok' => $processed > 0,
            'message' => $message,
            'processed' => $processed,
            'skipped' => $skipped,
            'archived_posts' => $archivedPosts,
        ];
    }

    /**
     * @param array<string, mixed> $topic
     */
    private function shouldSkipUnchanged(string $action, array $topic): bool
    {
        return match ($action) {
            'pin' => !empty($topic['is_pinned']),
            'unpin' => empty($topic['is_pinned']),
            'lock' => !empty($topic['is_locked']),
            'unlock' => empty($topic['is_locked']),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function recordAudit(int $staffId, string $action, int $topicId, array $meta = []): void
    {
        $meta['bulk'] = true;
        $this->app->auditLog()->record(
            $staffId,
            $action,
            'topic',
            $topicId,
            $this->app->request()->ip(),
            $meta,
        );
    }
}
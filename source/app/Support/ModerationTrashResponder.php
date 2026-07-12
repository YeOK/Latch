<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

use Latch\Core\Application;
use Latch\Core\Cache;

/**
 * Shared restore/purge handlers for admin and mod trash actions.
 */
trait ModerationTrashResponder
{
    abstract protected function staffApp(): Application;

    abstract protected function recordTrashRestore(int $postId, int $topicId): void;

    abstract protected function recordTrashPurge(int $postId, int $topicId): void;

    private function finishTrashRestore(int $id): void
    {
        $app = $this->staffApp();
        $trashPath = $app->moderationTrash()->trashBoardPath();
        $result = $app->moderationTrash()->restoreTrashedPost($id);
        if ($result === null) {
            $this->finishStaffAction(false, 'Trashed post not found.', $trashPath);
        }

        $topicId = (int) $result['restore_topic_id'];
        $topic = $app->topics()->findById($topicId);
        if ($topic === null) {
            $this->finishStaffAction(false, 'Original topic no longer exists.', $trashPath);
        }

        $app->topics()->recalculateLastPostAt($topicId);
        $app->indexSearchTopic($topicId);
        $app->invalidateCacheTags([
            Cache::tagTopic($topicId),
            Cache::tagBoard((int) $topic['board_id']),
            Cache::tagUser((int) $result['author_user_id']),
            Cache::tagSite(),
        ]);

        $this->recordTrashRestore($id, $topicId);
        $this->finishStaffAction(true, 'Post restored to its topic.', $trashPath);
    }

    private function finishTrashPurge(int $id): void
    {
        $app = $this->staffApp();
        $trashPath = $app->moderationTrash()->trashBoardPath();
        $post = $app->posts()->findById($id);
        $result = $app->moderationTrash()->purgeTrashedPost($id);
        if ($result === null) {
            $this->finishStaffAction(false, 'Trashed post not found.', $trashPath);
        }

        $topicId = (int) $result['restore_topic_id'];
        $topic = $app->topics()->findById($topicId);
        if ($post !== null && $topic !== null) {
            $app->firePostDelete($post, $topic);
        }
        if ($topic !== null) {
            $app->topics()->recalculateLastPostAt($topicId);
            $app->indexSearchTopic($topicId);
            $app->invalidateCacheTags([
                Cache::tagTopic($topicId),
                Cache::tagBoard((int) $topic['board_id']),
                Cache::tagSite(),
            ]);
        }

        $app->invalidateCacheTags([Cache::tagUser((int) $result['author_user_id'])]);
        $this->recordTrashPurge($id, $topicId);
        $this->finishStaffAction(true, 'Post permanently deleted.', $trashPath);
    }
}
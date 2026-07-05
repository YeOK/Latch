<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Collects cache tags during bulk staff actions and invalidates once at flush.
 */
final class BulkCacheCollector
{
    /** @var array<string, true> */
    private array $tags = [];

    /**
     * @param array<string, mixed> $topic
     */
    public function addTopic(array $topic): void
    {
        $topicId = (int) ($topic['id'] ?? 0);
        if ($topicId > 0) {
            $this->tags[Cache::tagTopic($topicId)] = true;
        }

        $boardId = (int) ($topic['board_id'] ?? 0);
        if ($boardId > 0) {
            $this->tags[Cache::tagBoard($boardId)] = true;
        }

        $userId = (int) ($topic['user_id'] ?? 0);
        if ($userId > 0) {
            $this->tags[Cache::tagUser($userId)] = true;
        }

        $this->tags[Cache::tagSite()] = true;
    }

    public function addBoard(int $boardId): void
    {
        if ($boardId > 0) {
            $this->tags[Cache::tagBoard($boardId)] = true;
            $this->tags[Cache::tagSite()] = true;
        }
    }

    public function addUser(int $userId): void
    {
        if ($userId > 0) {
            $this->tags[Cache::tagUser($userId)] = true;
        }
    }

    public function addSite(): void
    {
        $this->tags[Cache::tagSite()] = true;
    }

    public function flush(Application $app): void
    {
        if ($this->tags === []) {
            return;
        }

        $app->invalidateCacheTags(array_keys($this->tags));
    }

    /**
     * @return list<string>
     */
    public function collectedTags(): array
    {
        return array_keys($this->tags);
    }
}
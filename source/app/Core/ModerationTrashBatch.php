<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use Latch\Models\SearchRepository;

/**
 * Defers FTS updates during bulk topic archive/delete until flush().
 */
final class ModerationTrashBatch
{
    /** @var array<int, true> */
    private array $removeTopicIds = [];

    public function deferSearchRemove(int $topicId): void
    {
        if ($topicId > 0) {
            $this->removeTopicIds[$topicId] = true;
        }
    }

    public function flush(?SearchRepository $search): void
    {
        if ($search === null || !$search->isEnabled() || $this->removeTopicIds === []) {
            return;
        }

        foreach (array_keys($this->removeTopicIds) as $topicId) {
            $search->removeTopic($topicId);
        }
    }

    /**
     * @return list<int>
     */
    public function pendingRemovals(): array
    {
        return array_keys($this->removeTopicIds);
    }
}
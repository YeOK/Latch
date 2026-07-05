<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\ForumStats;

final class StatsPanel
{
    public function render(int $posts, int $topics, int $members): string
    {
        $postsLabel = $this->formatInteger($posts);
        $topicsLabel = $this->formatInteger($topics);
        $membersLabel = $this->formatInteger($members);

        return <<<HTML
<section class="forum-stats admin-stats" aria-label="Forum statistics">
    <div class="admin-stat-card">
        <span class="admin-stat-value">{$postsLabel}</span>
        <span class="admin-stat-label">Posts</span>
    </div>
    <div class="admin-stat-card">
        <span class="admin-stat-value">{$topicsLabel}</span>
        <span class="admin-stat-label">Topics</span>
    </div>
    <div class="admin-stat-card">
        <span class="admin-stat-value">{$membersLabel}</span>
        <span class="admin-stat-label">Registered members</span>
    </div>
</section>
HTML;
    }

    private function formatInteger(int $value): string
    {
        return number_format($value);
    }
}
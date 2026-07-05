<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use Latch\Models\PostRepository;
use Latch\Models\SettingRepository;

/**
 * Honeypot, link limits for new users, and mod approval queue (distinct from report quarantine).
 */
final class SpamGuard
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly PostRepository $posts,
        private readonly SecurityLog $securityLog,
        private readonly Request $request,
    ) {
    }

    public function honeypotEnabled(): bool
    {
        return $this->settings->getBool('spam_honeypot_enabled', true);
    }

    public function honeypotTriggered(): bool
    {
        if (!$this->honeypotEnabled()) {
            return false;
        }

        return trim((string) $this->request->input('website', '')) !== '';
    }

    public function logHoneypot(int $userId): void
    {
        $this->securityLog->log('spam_honeypot', [
            'ip' => $this->request->ip(),
            'user_id' => $userId,
        ]);
    }

    public function linkLimitError(string $body, array $user): ?string
    {
        $maxLinks = (int) $this->settings->get('spam_link_limit_new_users', '2');
        if ($maxLinks <= 0) {
            return null;
        }

        if (!$this->isNewUser($user)) {
            return null;
        }

        $count = $this->countLinks($body);
        if ($count <= $maxLinks) {
            return null;
        }

        return sprintf(
            'New members may include at most %d link%s per post. Remove %d link%s or wait until you have more posts.',
            $maxLinks,
            $maxLinks === 1 ? '' : 's',
            $count - $maxLinks,
            ($count - $maxLinks) === 1 ? '' : 's',
        );
    }

    public function approvalStatusForUser(array $user): string
    {
        if (!$this->settings->getBool('spam_approval_queue_enabled', true)) {
            return PostRepository::APPROVAL_APPROVED;
        }

        $role = (string) ($user['role'] ?? '');
        if (in_array($role, ['admin', 'mod'], true)) {
            return PostRepository::APPROVAL_APPROVED;
        }

        if (!$this->isNewUser($user)) {
            return PostRepository::APPROVAL_APPROVED;
        }

        return PostRepository::APPROVAL_PENDING;
    }

    public function isNewUser(array $user): bool
    {
        $threshold = max(0, (int) $this->settings->get('spam_new_user_max_posts', '5'));
        if ($threshold <= 0) {
            return false;
        }

        $postCount = $this->posts->countApprovedByUser((int) $user['id']);

        return $postCount < $threshold;
    }

    private function countLinks(string $body): int
    {
        $patterns = [
            '/\[url=(https?:\/\/[^\]]+)\]/i',
            '/\[url\](https?:\/\/[^\[]+)\[\/url\]/i',
            '/\[(https?:\/\/[^\]]+)\]/i',
            '/https?:\/\/[^\s<>\[\]"\'\)]+/i',
        ];

        $count = 0;
        foreach ($patterns as $pattern) {
            $count += preg_match_all($pattern, $body) ?: 0;
        }

        return $count;
    }
}
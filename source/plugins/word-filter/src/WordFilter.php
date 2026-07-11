<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\WordFilter;

use Latch\Core\Auth;
use Latch\Core\Plugins\PostSaveContext;

/**
 * Scan topic bodies (and new topic titles) against the blocked-word list.
 */
final class WordFilter
{
    private const REJECT_MESSAGE = 'Your post contains language that is not allowed on this forum.';

    private readonly TextNormalizer $normalizer;
    private readonly WordMatcher $matcher;

    public function __construct(
        private readonly Settings $settings,
    ) {
        $this->normalizer = new TextNormalizer();
        $this->matcher = new WordMatcher(
            $this->normalizer,
            $this->settings->caseSensitive,
            $this->settings->blockedWords,
        );
    }

    public function process(PostSaveContext $ctx): ?string
    {
        if ($this->settings->blockedWords === []) {
            return null;
        }

        if ($this->settings->staffBypass && $this->isStaff($ctx->user)) {
            return null;
        }

        if ($this->settings->appliesToTopicTitle() && $ctx->kind === 'topic' && $ctx->topicTitle !== null) {
            $titleScan = $this->normalizer->scannableCopy($ctx->topicTitle);
            if ($this->matcher->contains($titleScan)) {
                return self::REJECT_MESSAGE;
            }
        }

        if (!$this->settings->appliesToBody()) {
            return null;
        }

        $scanText = $this->normalizer->scannableCopy($ctx->body);
        $matches = $this->matcher->findAll($scanText);
        if ($matches === []) {
            return null;
        }

        if ($this->settings->mode === Settings::MODE_BLOCK) {
            return self::REJECT_MESSAGE;
        }

        $ctx->body = $this->maskMatches($ctx->body, $matches);

        return null;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function isStaff(array $user): bool
    {
        $role = (string) ($user['role'] ?? Auth::ROLE_MEMBER);

        return in_array($role, [Auth::ROLE_ADMIN, Auth::ROLE_MOD], true);
    }

    /**
     * @param list<array{start: int, end: int, word: string}> $matches
     */
    private function maskMatches(string $body, array $matches): string
    {
        $chars = preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            return $body;
        }

        foreach ($matches as $match) {
            $length = $match['end'] - $match['start'];
            for ($offset = 0; $offset < $length; $offset++) {
                $index = $match['start'] + $offset;
                if (!isset($chars[$index])) {
                    continue;
                }

                $chars[$index] = '*';
            }
        }

        return implode('', $chars);
    }
}
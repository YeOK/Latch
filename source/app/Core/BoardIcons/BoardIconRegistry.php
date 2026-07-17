<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\BoardIcons;

use Latch\Core\Config;
use RuntimeException;

/**
 * Resolves board icons from the built-in pack, per-board icon_key, or plugin registrations.
 */
final class BoardIconRegistry
{
    private const DEFAULT_KEY = 'default';

    /** @var array<string, list<string>> */
    private const KEYWORD_MAP = [
        'news' => ['news', 'headline', 'headlines', 'press', 'media'],
        'announcements' => ['announcement', 'announcements', 'notice', 'notices', 'update', 'updates'],
        'general' => ['general', 'discussion', 'discussions', 'chat', 'main', 'community'],
        'help' => ['help', 'faq', 'question', 'questions', 'howto', 'how-to'],
        'support' => ['support', 'ticket', 'tickets', 'assistance'],
        'feedback' => ['feedback', 'suggestion', 'suggestions', 'ideas', 'feature'],
        'gaming' => ['gaming', 'game', 'games', 'xbox', 'playstation', 'nintendo'],
        'tech' => ['tech', 'technology', 'software', 'hardware', 'it'],
        'dev' => ['dev', 'development', 'coding', 'programming', 'code', 'api'],
        'lounge' => ['off-topic', 'offtopic', 'off topic', 'lounge', 'random', 'watercooler', 'social', 'chill'],
        'meta' => ['meta', 'site', 'about'],
        'introductions' => ['introduction', 'introductions', 'welcome', 'newbie', 'newbies', 'intro', 'intros'],
        'staff' => ['staff', 'moderator', 'moderators', 'mod', 'mods', 'team'],
        'marketplace' => ['marketplace', 'market', 'buy', 'sell', 'trade', 'classified', 'classifieds'],
        'sports' => ['sports', 'sport', 'football', 'soccer', 'fitness'],
        'music' => ['music', 'audio', 'bands', 'artists'],
        'creative' => ['creative', 'art', 'design', 'photo', 'photography', 'writing'],
    ];

    /** @var array<string, string> */
    private array $svgByKey = [];

    /** @var array<string, list<string>> */
    private array $keywordMap;

    private string $packPath;

    public function __construct(Config $config, ?string $activeTheme = null)
    {
        $this->keywordMap = self::KEYWORD_MAP;

        $themesPath = (string) $config->get('paths.themes');
        $active = $activeTheme ?? (string) $config->get('theme.active', 'default');
        $activePack = $themesPath . '/' . $active . '/assets/img/board-icons';
        $defaultPack = $themesPath . '/default/assets/img/board-icons';

        $this->packPath = is_dir($activePack) ? $activePack : $defaultPack;
        $this->loadPackDirectory($this->packPath);

        if ($this->packPath !== $defaultPack && is_dir($defaultPack)) {
            $this->loadPackDirectory($defaultPack, mergeOnlyMissing: true);
        }
    }

    /**
     * Register or override an icon (used by plugins).
     */
    public function register(string $key, string $svgMarkup): void
    {
        $key = $this->normalizeKey($key);
        if ($key === '' || !$this->isSafeSvg($svgMarkup)) {
            throw new RuntimeException('Invalid board icon registration.');
        }

        $this->svgByKey[$key] = $svgMarkup;
    }

    /**
     * Add or replace keyword hints used by {@see suggestKey()} for plugin icon packs.
     *
     * @param list<string> $keywords
     */
    public function registerKeywords(string $key, array $keywords): void
    {
        $key = $this->normalizeKey($key);
        if ($key === '' || !$this->has($key)) {
            throw new RuntimeException('Board icon keywords require a registered icon key.');
        }

        $normalized = [];
        foreach ($keywords as $keyword) {
            if (!is_string($keyword)) {
                continue;
            }
            $token = $this->normalizeKey($keyword);
            if ($token !== '') {
                $normalized[] = $token;
            }
        }

        if ($normalized === []) {
            return;
        }

        $this->keywordMap[$key] = array_values(array_unique($normalized));
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->svgByKey);
    }

    public function suggestKey(string $name, string $slug): string
    {
        $haystack = $this->normalizeKey($slug) . ' ' . $this->normalizeKey($name);

        foreach ($this->keywordMap as $key => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $this->normalizeKey($keyword))) {
                    return $this->has($key) ? $key : self::DEFAULT_KEY;
                }
            }
        }

        return self::DEFAULT_KEY;
    }

    /**
     * @param array<string, mixed> $board
     */
    public function resolveKey(array $board): string
    {
        $stored = $this->normalizeKey((string) ($board['icon_key'] ?? ''));
        if ($stored !== '' && $this->has($stored)) {
            return $stored;
        }

        $name = (string) ($board['name'] ?? '');
        $slug = (string) ($board['slug'] ?? '');

        return $this->suggestKey($name, $slug);
    }

    public function svg(string $key): string
    {
        $key = $this->normalizeKey($key);

        return $this->svgByKey[$key] ?? $this->svgByKey[self::DEFAULT_KEY];
    }

    /**
     * @param array<string, mixed> $board
     */
    public function svgForBoard(array $board): string
    {
        return $this->svg($this->resolveKey($board));
    }

    public function has(string $key): bool
    {
        return isset($this->svgByKey[$this->normalizeKey($key)]);
    }

    private function loadPackDirectory(string $directory, bool $mergeOnlyMissing = false): void
    {
        foreach (glob($directory . '/*.svg') ?: [] as $path) {
            $key = $this->normalizeKey(pathinfo($path, PATHINFO_FILENAME));
            if ($key === '' || ($mergeOnlyMissing && isset($this->svgByKey[$key]))) {
                continue;
            }

            $svg = trim((string) file_get_contents($path));
            if ($this->isSafeSvg($svg)) {
                $this->svgByKey[$key] = $svg;
            }
        }

        if (!isset($this->svgByKey[self::DEFAULT_KEY])) {
            throw new RuntimeException('Board icon pack is missing default.svg');
        }
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }

    private function isSafeSvg(string $svg): bool
    {
        if ($svg === '' || !str_starts_with($svg, '<svg')) {
            return false;
        }

        $lower = strtolower($svg);

        return !str_contains($lower, '<script')
            && !str_contains($lower, 'onload=')
            && !str_contains($lower, 'onclick=');
    }
}
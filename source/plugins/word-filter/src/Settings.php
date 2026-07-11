<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\WordFilter;

use Latch\Core\Plugins\PluginManifest;
use Latch\Core\Plugins\PluginSettingsStore;

/**
 * Plugin settings merged from manifest defaults, settings.json, and bundled word list.
 */
final class Settings
{
    public const MODE_BLOCK = 'block';
    public const MODE_MASK = 'mask';

    /** @var list<string> */
    private const DEFAULT_APPLY_TO = ['body', 'topic_title'];

    /**
     * @param list<string> $blockedWords
     * @param list<string> $applyTo
     */
    public function __construct(
        public readonly string $mode,
        public readonly bool $caseSensitive,
        public readonly bool $staffBypass,
        public readonly array $applyTo,
        public readonly array $blockedWords,
    ) {
    }

    public static function load(string $pluginDir, string $storageRoot, PluginManifest $manifest): self
    {
        $store = PluginSettingsStore::forPlugin($manifest, $storageRoot);
        $values = $store->all();

        $mode = (string) ($values['mode'] ?? self::MODE_BLOCK);
        if (!in_array($mode, [self::MODE_BLOCK, self::MODE_MASK], true)) {
            $mode = self::MODE_BLOCK;
        }

        $applyTo = $values['apply_to'] ?? self::DEFAULT_APPLY_TO;
        if (!is_array($applyTo) || $applyTo === []) {
            $applyTo = self::DEFAULT_APPLY_TO;
        }

        $applyTo = array_values(array_filter(
            array_map(static fn ($entry): string => is_string($entry) ? trim($entry) : '', $applyTo),
            static fn (string $entry): bool => in_array($entry, ['body', 'topic_title'], true),
        ));
        if ($applyTo === []) {
            $applyTo = self::DEFAULT_APPLY_TO;
        }

        $bundled = self::loadWordList($pluginDir . '/data/blocked-words.txt');
        $extra = self::normalizeWordEntries($values['extra_words'] ?? []);
        $blocked = array_values(array_unique(array_merge($bundled, $extra)));

        return new self(
            mode: $mode,
            caseSensitive: (bool) ($values['case_sensitive'] ?? false),
            staffBypass: (bool) ($values['staff_bypass'] ?? true),
            applyTo: $applyTo,
            blockedWords: $blocked,
        );
    }

    public function appliesToBody(): bool
    {
        return in_array('body', $this->applyTo, true);
    }

    public function appliesToTopicTitle(): bool
    {
        return in_array('topic_title', $this->applyTo, true);
    }

    /**
     * @return list<string>
     */
    private static function loadWordList(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $words = [];
        foreach ($lines as $line) {
            if (!is_string($line)) {
                continue;
            }

            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $words[] = $line;
        }

        return $words;
    }

    /**
     * @return list<string>
     */
    private static function normalizeWordEntries(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }

        $words = [];
        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $entry = trim($entry);
            if ($entry === '' || str_starts_with($entry, '#')) {
                continue;
            }

            $words[] = $entry;
        }

        return $words;
    }
}
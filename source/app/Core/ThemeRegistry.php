<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Discovers installed Twig theme packs under themes/.
 */
final class ThemeRegistry
{
    public const SETTING_ACTIVE = 'active_theme';

    public function __construct(
        private readonly string $themesPath,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, version: string, description: string, author: string}>
     */
    public function installed(): array
    {
        $root = realpath($this->themesPath);
        if ($root === false || !is_dir($root)) {
            return [];
        }

        $themes = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!$this->isValidId($entry)) {
                continue;
            }

            $dir = $root . '/' . $entry;
            if (!is_dir($dir) || !$this->looksLikeTheme($dir)) {
                continue;
            }

            $manifest = $this->readManifest($dir);
            $themes[] = [
                'id' => $entry,
                'name' => (string) ($manifest['name'] ?? $this->labelFromId($entry)),
                'version' => (string) ($manifest['version'] ?? ''),
                'description' => (string) ($manifest['description'] ?? ''),
                'author' => (string) ($manifest['author'] ?? ''),
            ];
        }

        usort($themes, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $themes;
    }

    public function isValid(string $id): bool
    {
        if (!$this->isValidId($id)) {
            return false;
        }

        $root = realpath($this->themesPath);
        if ($root === false) {
            return false;
        }

        $dir = realpath($root . '/' . $id);

        return $dir !== false
            && str_starts_with($dir, $root . DIRECTORY_SEPARATOR)
            && is_dir($dir)
            && $this->looksLikeTheme($dir);
    }

    public function resolve(string $candidate, string $fallback = 'default'): string
    {
        $candidate = trim($candidate);
        if ($candidate !== '' && $this->isValid($candidate)) {
            return $candidate;
        }

        return $this->isValid($fallback) ? $fallback : 'default';
    }

    /** Stable per-pack salt so asset ?v= changes when active_theme changes (not only when CSS mtimes change). */
    public static function assetVersionSalt(string $activeThemeId): int
    {
        return crc32($activeThemeId) & 0x7FFFFFFF;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function manifest(string $id): ?array
    {
        if (!$this->isValid($id)) {
            return null;
        }

        $root = realpath($this->themesPath);
        if ($root === false) {
            return null;
        }

        return $this->readManifest($root . '/' . $id);
    }

    private function isValidId(string $id): bool
    {
        return $id !== ''
            && preg_match('/^[a-z][a-z0-9_-]*$/', $id) === 1;
    }

    private function looksLikeTheme(string $dir): bool
    {
        return is_file($dir . '/theme.json')
            || is_dir($dir . '/layouts')
            || is_dir($dir . '/assets');
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $dir): array
    {
        $file = $dir . '/theme.json';
        if (!is_file($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function labelFromId(string $id): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $id));
    }
}
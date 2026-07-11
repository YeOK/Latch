<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use RuntimeException;

/**
 * File-backed cache with tag-based invalidation.
 * Supports full guest pages (`pages/`) and HTML fragments (`fragments/`).
 */
final class Cache
{
    private readonly string $pagesDir;
    private readonly string $fragmentsDir;
    private readonly string $tagsDir;

    public function __construct(string $storagePath)
    {
        $base = rtrim($storagePath, '/') . '/cache';
        $this->pagesDir = $base . '/pages';
        $this->fragmentsDir = $base . '/fragments';
        $this->tagsDir = $base . '/tags';

        foreach ([$this->pagesDir, $this->fragmentsDir, $this->tagsDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create cache directory: ' . $dir);
            }
        }
    }

    public function get(string $key): ?string
    {
        return $this->readEntry($this->pagePath($key), $key);
    }

    public function getFragment(string $key): ?string
    {
        return $this->readEntry($this->fragmentPath($key), $key);
    }

    /**
     * @param list<string> $tags
     */
    public function set(string $key, string $body, int $ttlSeconds, array $tags = []): void
    {
        $this->writeEntry($this->pagePath($key), $key, $body, $ttlSeconds, $tags);
    }

    /**
     * @param list<string> $tags
     */
    public function setFragment(string $key, string $body, int $ttlSeconds, array $tags = []): void
    {
        $this->writeEntry($this->fragmentPath($key), $key, $body, $ttlSeconds, $tags);
    }

    public function delete(string $key): void
    {
        $this->deleteKey($key);
    }

    public function invalidateTag(string $tag): void
    {
        $tagFile = $this->tagPath($tag);
        if (!is_file($tagFile)) {
            return;
        }

        $keys = json_decode((string) file_get_contents($tagFile), true);
        if (!is_array($keys)) {
            @unlink($tagFile);

            return;
        }

        foreach ($keys as $key) {
            if (is_string($key)) {
                $this->deleteKey($key);
            }
        }

        @unlink($tagFile);
    }

    /**
     * @param list<string> $tags
     */
    public function invalidateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->invalidateTag($tag);
        }
    }

    public function purgeAll(): int
    {
        $count = 0;

        foreach ([$this->pagesDir, $this->fragmentsDir] as $dir) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }

        foreach (glob($this->tagsDir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }

        return $count;
    }

    public static function makeKey(string $route, array $params = []): string
    {
        ksort($params);
        $normalized = $route . '|' . http_build_query($params);

        return hash('sha256', $normalized);
    }

    public static function makeFragmentKey(string $fragmentId, array $params = []): string
    {
        ksort($params);
        $normalized = 'frag:' . $fragmentId . '|' . http_build_query($params);

        return hash('sha256', $normalized);
    }

    public static function tagBoard(int $boardId): string
    {
        return 'board:' . $boardId;
    }

    public static function tagTopic(int $topicId): string
    {
        return 'topic:' . $topicId;
    }

    public static function tagSite(): string
    {
        return 'site';
    }

    public static function tagUser(int $userId): string
    {
        return 'user:' . $userId;
    }

    public static function tagPlugin(string $slug): string
    {
        return 'plugin:' . $slug;
    }

    private function readEntry(string $path, string $key): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['expires'], $data['body'])) {
            @unlink($path);

            return null;
        }

        if ((int) $data['expires'] < time()) {
            $this->deleteKey($key);

            return null;
        }

        return (string) $data['body'];
    }

    /**
     * @param list<string> $tags
     */
    private function writeEntry(string $path, string $key, string $body, int $ttlSeconds, array $tags): void
    {
        $payload = json_encode([
            'expires' => time() + max(1, $ttlSeconds),
            'body' => $body,
            'tags' => $tags,
        ], JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Cache write failed: ' . $key);
        }

        foreach ($tags as $tag) {
            $this->addKeyToTag($tag, $key);
        }
    }

    private function deleteKey(string $key): void
    {
        foreach ([$this->pagePath($key), $this->fragmentPath($key)] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function pagePath(string $key): string
    {
        return $this->pagesDir . '/' . $this->safeFilename($key) . '.json';
    }

    private function fragmentPath(string $key): string
    {
        return $this->fragmentsDir . '/' . $this->safeFilename($key) . '.json';
    }

    private function tagPath(string $tag): string
    {
        return $this->tagsDir . '/' . $this->safeFilename($tag) . '.json';
    }

    private function safeFilename(string $value): string
    {
        return hash('sha256', $value);
    }

    private function addKeyToTag(string $tag, string $key): void
    {
        $path = $this->tagPath($tag);
        $keys = [];

        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $keys = $decoded;
            }
        }

        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
        }

        file_put_contents($path, json_encode($keys, JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
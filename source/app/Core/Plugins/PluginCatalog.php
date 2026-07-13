<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use InvalidArgumentException;
use RuntimeException;

/**
 * Fetches and caches the official Latch-plugins catalog.json index.
 */
final class PluginCatalog
{
    public const DEFAULT_CATALOG_URL = 'https://raw.githubusercontent.com/YeOK/Latch-plugins/main/catalog.json';
    public const DEFAULT_RELEASE_REPO = 'YeOK/Latch-plugins';

    public function __construct(
        private readonly string $cacheFile,
        private readonly string $catalogUrl = self::DEFAULT_CATALOG_URL,
        private readonly string $releaseRepo = self::DEFAULT_RELEASE_REPO,
        private readonly int $cacheTtlSeconds = 3600,
        private readonly ?PluginHttpClientInterface $http = null,
    ) {
    }

    /**
     * @return array{
     *     name: string,
     *     release: string,
     *     latch_min_version: string,
     *     entries: list<PluginCatalogEntry>
     * }|null
     */
    public function load(bool $forceRefresh = false): ?array
    {
        if (!$forceRefresh) {
            $cached = $this->readCache();
            if ($cached !== null) {
                return $cached;
            }
        }

        $body = $this->httpClient()->get($this->catalogUrl);
        if ($body === null) {
            return $this->readCache(ignoreTtl: true);
        }

        try {
            $parsed = $this->parseCatalogJson($body);
        } catch (\Throwable) {
            return $this->readCache(ignoreTtl: true);
        }

        $this->writeCache($parsed);

        return $parsed;
    }

    public function findUpdateEntry(string $slug, string $installedVersion, bool $forceRefresh = false): ?PluginCatalogEntry
    {
        $entry = $this->findEntry($slug, $forceRefresh);
        if ($entry === null) {
            return null;
        }

        return version_compare($entry->version, $installedVersion, '>') ? $entry : null;
    }

    public function findEntry(string $slug, bool $forceRefresh = false): ?PluginCatalogEntry
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $catalog = $this->load($forceRefresh);
        if ($catalog === null) {
            return null;
        }

        foreach ($catalog['entries'] as $entry) {
            if ($entry->slug === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<PluginCatalogEntry>
     */
    public function availableEntries(array $installedSlugs, bool $forceRefresh = false): array
    {
        $catalog = $this->load($forceRefresh);
        if ($catalog === null) {
            return [];
        }

        $installed = array_fill_keys($installedSlugs, true);
        $available = [];
        foreach ($catalog['entries'] as $entry) {
            if (!isset($installed[$entry->slug])) {
                $available[] = $entry;
            }
        }

        return $available;
    }

    public function releaseRepo(): string
    {
        return $this->releaseRepo;
    }

    /**
     * @return array{
     *     name: string,
     *     release: string,
     *     latch_min_version: string,
     *     entries: list<PluginCatalogEntry>
     * }
     */
    public function parseCatalogJson(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Catalog JSON must decode to an object');
        }

        $name = trim((string) ($data['name'] ?? ''));
        $release = trim((string) ($data['release'] ?? ''));
        $latchMin = trim((string) ($data['latch_min_version'] ?? ''));
        if ($name === '' || $release === '' || $latchMin === '') {
            throw new InvalidArgumentException('Catalog JSON requires name, release, and latch_min_version');
        }

        $plugins = $data['plugins'] ?? null;
        if (!is_array($plugins)) {
            throw new InvalidArgumentException('Catalog JSON requires a plugins array');
        }

        $entries = [];
        foreach ($plugins as $plugin) {
            if (!is_array($plugin)) {
                continue;
            }

            $entries[] = PluginCatalogEntry::fromArray($plugin);
        }

        if ($entries === []) {
            throw new InvalidArgumentException('Catalog JSON contains no plugin entries');
        }

        usort($entries, static fn (PluginCatalogEntry $a, PluginCatalogEntry $b): int => strcmp($a->slug, $b->slug));

        return [
            'name' => $name,
            'release' => $release,
            'latch_min_version' => $latchMin,
            'entries' => $entries,
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     release: string,
     *     latch_min_version: string,
     *     entries: list<PluginCatalogEntry>
     * }|null
     */
    private function readCache(bool $ignoreTtl = false): ?array
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }

        $raw = file_get_contents($this->cacheFile);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $wrapper = json_decode($raw, true);
        if (!is_array($wrapper)) {
            return null;
        }

        if (($wrapper['catalog_url'] ?? '') !== $this->catalogUrl) {
            return null;
        }

        $fetchedAt = (int) ($wrapper['fetched_at'] ?? 0);
        if (!$ignoreTtl && $fetchedAt > 0 && (time() - $fetchedAt) > $this->cacheTtlSeconds) {
            return null;
        }

        $data = $wrapper['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        try {
            return $this->parseCatalogArray($data);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *     name: string,
     *     release: string,
     *     latch_min_version: string,
     *     entries: list<PluginCatalogEntry>
     * }
     */
    private function parseCatalogArray(array $data): array
    {
        return $this->parseCatalogJson((string) json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array{
     *     name: string,
     *     release: string,
     *     latch_min_version: string,
     *     entries: list<PluginCatalogEntry>
     * } $catalog
     */
    private function writeCache(array $catalog): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create plugin catalog cache directory');
        }

        $payload = [
            'catalog_url' => $this->catalogUrl,
            'fetched_at' => time(),
            'data' => [
                'name' => $catalog['name'],
                'release' => $catalog['release'],
                'latch_min_version' => $catalog['latch_min_version'],
                'plugins' => array_map(
                    static fn (PluginCatalogEntry $entry): array => [
                        'slug' => $entry->slug,
                        'name' => $entry->name,
                        'version' => $entry->version,
                        'min_latch_version' => $entry->minLatchVersion,
                        'summary' => $entry->summary,
                        'hooks' => $entry->hooks,
                        'database' => $entry->database,
                    ],
                    $catalog['entries'],
                ),
            ],
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        if (file_put_contents($this->cacheFile, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Could not write plugin catalog cache');
        }
    }

    private function httpClient(): PluginHttpClientInterface
    {
        return $this->http ?? new PluginHttpClient();
    }
}
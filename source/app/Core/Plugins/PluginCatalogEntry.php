<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use InvalidArgumentException;

/**
 * One plugin row from the Latch-plugins catalog.json index.
 */
final readonly class PluginCatalogEntry
{
    /**
     * @param list<string> $hooks
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $version,
        public string $minLatchVersion,
        public string $summary,
        public array $hooks,
        public bool $database = false,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $slug = trim((string) ($data['slug'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $version = trim((string) ($data['version'] ?? ''));
        $minLatch = trim((string) ($data['min_latch_version'] ?? ''));
        $summary = trim((string) ($data['summary'] ?? ''));

        if ($slug === '' || $name === '' || $version === '' || $minLatch === '') {
            throw new InvalidArgumentException('Catalog plugin entry requires slug, name, version, and min_latch_version');
        }

        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            throw new InvalidArgumentException("Invalid catalog plugin slug: {$slug}");
        }

        $hooks = [];
        foreach ($data['hooks'] ?? [] as $hook) {
            if (is_string($hook) && $hook !== '') {
                $hooks[] = $hook;
            }
        }

        return new self(
            slug: $slug,
            name: $name,
            version: $version,
            minLatchVersion: $minLatch,
            summary: $summary,
            hooks: $hooks,
            database: (bool) ($data['database'] ?? false),
        );
    }

    public function releaseZipUrl(string $releaseRepo, string $releaseTag): string
    {
        $repo = trim($releaseRepo, '/');
        $tag = trim($releaseTag);
        if ($repo === '' || $tag === '') {
            throw new InvalidArgumentException('Release repo and tag are required');
        }

        return sprintf(
            'https://github.com/%s/releases/download/%s/%s-%s.zip',
            $repo,
            rawurlencode($tag),
            $this->slug,
            $this->version,
        );
    }

    public function isCompatibleWith(string $latchVersion): bool
    {
        return version_compare($latchVersion, $this->minLatchVersion, '>=');
    }
}
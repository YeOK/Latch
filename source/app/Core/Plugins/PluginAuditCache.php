<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use RuntimeException;

/**
 * File-backed cache of plugin audit reports (keyed by slug + content fingerprint).
 */
final class PluginAuditCache
{
    public function __construct(
        private readonly string $cacheDir,
    ) {
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 02775, true) && !is_dir($this->cacheDir)) {
            throw new RuntimeException('Cannot create plugin audit cache directory: ' . $this->cacheDir);
        }

        PluginStoragePermissions::ensureWritable($this->cacheDir);
    }

    /**
     * @return array{scanned_at: string, fingerprint: string, report: PluginAuditReport}|null
     */
    public function get(string $slug, string $fingerprint): ?array
    {
        $path = $this->pathForSlug($slug);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        $data = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($data)) {
            return null;
        }

        if (($data['fingerprint'] ?? '') !== $fingerprint) {
            return null;
        }

        $reportData = $data['report'] ?? null;
        if (!is_array($reportData)) {
            return null;
        }

        $scannedAt = (string) ($data['scanned_at'] ?? '');
        if ($scannedAt === '') {
            return null;
        }

        return [
            'scanned_at' => $scannedAt,
            'fingerprint' => $fingerprint,
            'report' => PluginAuditReport::fromArray($reportData),
        ];
    }

    public function put(string $slug, string $fingerprint, PluginAuditReport $report): void
    {
        $payload = json_encode([
            'slug' => $slug,
            'fingerprint' => $fingerprint,
            'scanned_at' => gmdate('c'),
            'report' => $report->toArray(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        PluginStoragePermissions::ensureWritable($this->cacheDir);

        if (file_put_contents($this->pathForSlug($slug), $payload, LOCK_EX) === false) {
            $hint = is_writable($this->cacheDir)
                ? ''
                : ' Fix: sudo latch fix-perms — or use sudo latch plugin enable ' . $slug
                    . ' (not sudo php bin/latch).';

            throw new RuntimeException('Failed to write plugin audit cache for ' . $slug . '.' . $hint);
        }
    }

    public function forget(string $slug): void
    {
        $path = $this->pathForSlug($slug);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param list<string> $activeSlugs
     */
    public function pruneExcept(array $activeSlugs): int
    {
        $keep = array_fill_keys($activeSlugs, true);
        $removed = 0;

        foreach (scandir($this->cacheDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.json')) {
                continue;
            }

            $slug = substr($entry, 0, -5);
            if ($slug === '' || isset($keep[$slug])) {
                continue;
            }

            if (@unlink($this->cacheDir . '/' . $entry)) {
                ++$removed;
            }
        }

        return $removed;
    }

    private function pathForSlug(string $slug): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            throw new RuntimeException('Invalid plugin slug for audit cache: ' . $slug);
        }

        return $this->cacheDir . '/' . $slug . '.json';
    }
}
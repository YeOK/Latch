<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

/**
 * Cached plugin audits — refresh when the plugin tree fingerprint changes.
 */
final class PluginAuditService
{
    public function __construct(
        private readonly PluginAuditor $auditor,
        private readonly PluginAuditCache $cache,
    ) {
    }

    /**
     * @return array{report: PluginAuditReport, from_cache: bool, scanned_at: string}
     */
    public function getOrScan(PluginManifest $manifest, bool $forceRefresh = false): array
    {
        $fingerprint = PluginFingerprint::forDirectory($manifest->pluginDir);

        if (!$forceRefresh) {
            $cached = $this->cache->get($manifest->slug, $fingerprint);
            if ($cached !== null) {
                return [
                    'report' => $cached['report'],
                    'from_cache' => true,
                    'scanned_at' => $cached['scanned_at'],
                ];
            }
        }

        $report = $this->auditor->auditPath($manifest->pluginDir);
        $scannedAt = gmdate('c');

        try {
            $this->cache->put($manifest->slug, $fingerprint, $report);
        } catch (\Throwable $e) {
            error_log('Plugin audit cache write failed for ' . $manifest->slug . ': ' . $e->getMessage());
        }

        return [
            'report' => $report,
            'from_cache' => false,
            'scanned_at' => $scannedAt,
        ];
    }

    /**
     * @return array{scanned: int, cached: int, failed: int, pruned: int}
     */
    public function auditDiscoveredPlugins(PluginRegistry $registry, bool $forceRefresh = false): array
    {
        $stats = [
            'scanned' => 0,
            'cached' => 0,
            'failed' => 0,
            'pruned' => 0,
        ];

        $slugs = [];
        foreach ($registry->discover() as $manifest) {
            $slugs[] = $manifest->slug;
            $result = $this->getOrScan($manifest, $forceRefresh);
            if ($result['from_cache']) {
                ++$stats['cached'];
            } else {
                ++$stats['scanned'];
            }

            if (!$result['report']->passed()) {
                ++$stats['failed'];
            }
        }

        $stats['pruned'] = $this->cache->pruneExcept($slugs);

        return $stats;
    }

    public function forget(string $slug): void
    {
        $this->cache->forget($slug);
    }
}
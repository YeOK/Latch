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
 * Admin/catalog install — download release zip, copy into plugins/, audit gate, leave disabled.
 */
final class PluginCatalogInstaller
{
    public function __construct(
        private readonly PluginInstaller $installer,
        private readonly PluginAuditService $auditService,
        private readonly PluginRegistry $registry,
        private readonly PluginReleaseDownloader $downloader,
        private readonly string $storagePath,
    ) {
    }

    public function install(PluginCatalogEntry $entry, string $releaseTag): PluginManifest
    {
        $zipPath = null;
        $manifest = null;

        try {
            $zipPath = $this->downloader->downloadEntry($entry, $releaseTag);
            $manifest = $this->installer->installFromSource($zipPath);
        } catch (\Throwable $e) {
            if ($manifest instanceof PluginManifest) {
                $this->rollbackInstall($manifest->slug);
            }

            throw $e;
        } finally {
            if ($zipPath !== null && is_file($zipPath)) {
                @unlink($zipPath);
            }
        }

        try {
            $result = $this->auditService->getOrScan($manifest, true);
            $report = $result['report'];
        } catch (\Throwable $e) {
            $this->rollbackInstall($manifest->slug);
            throw $e;
        }

        if (!$report->passed()) {
            $this->rollbackInstall($manifest->slug);
            throw new RuntimeException($report->toHuman() . "Install rolled back — fix critical audit findings and retry.\n");
        }

        $this->registry->disable($manifest->slug);

        $pluginStorageDir = rtrim($this->storagePath, '/') . '/plugins/' . $manifest->slug;
        if (is_dir($pluginStorageDir)) {
            PluginStoragePermissions::ensureWritable($pluginStorageDir);
        }

        return $manifest;
    }

    private function rollbackInstall(string $slug): void
    {
        try {
            $this->installer->removeInstalled($slug);
        } catch (\Throwable) {
            // Best effort — install may not have completed.
        }

        $this->auditService->forget($slug);
    }
}
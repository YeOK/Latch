<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

/**
 * Outcome of replacing an installed plugin tree — commit or rollback after audit.
 */
final class PluginUpgradeResult
{
    public function __construct(
        public readonly PluginManifest $manifest,
        private readonly PluginInstaller $installer,
        private readonly string $slug,
        private readonly string $backupDir,
    ) {
    }

    public function commit(): void
    {
        $this->installer->discardSnapshot($this->backupDir);
    }

    public function rollback(): void
    {
        $this->installer->restoreFromSnapshot($this->slug, $this->backupDir);
        $this->installer->discardSnapshot($this->backupDir);
    }
}
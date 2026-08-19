<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use Latch\Core\Application;

/**
 * Per-plugin bootstrap context (limited surface for third-party code).
 */
final class PluginContext
{
    public function __construct(
        private readonly Application $app,
        private readonly PluginManifest $manifest,
        private readonly HookRegistry $hooks,
    ) {
    }

    /** Forum kernel. Avoid storing long-lived state on it. */
    public function app(): Application
    {
        return $this->app;
    }

    public function manifest(): PluginManifest
    {
        return $this->manifest;
    }

    /** Register listeners. Declare every hook in plugin.json first. */
    public function hooks(): PluginHookRegistrar
    {
        return new PluginHookRegistrar($this->hooks, $this->manifest);
    }

    /** Plugin root on disk (assets/, migrations/, plugin.json). */
    public function path(): string
    {
        return $this->manifest->pluginDir;
    }

    public function slug(): string
    {
        return $this->manifest->slug;
    }

    /**
     * Per-plugin SQLite (null when manifest database is disabled or not yet migrated).
     */
    public function database(): ?PluginDatabase
    {
        return $this->app->pluginDatabaseManager()->open($this->manifest);
    }
}
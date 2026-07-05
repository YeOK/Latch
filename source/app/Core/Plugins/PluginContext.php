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

    public function app(): Application
    {
        return $this->app;
    }

    public function manifest(): PluginManifest
    {
        return $this->manifest;
    }

    public function hooks(): HookRegistry
    {
        return $this->hooks;
    }

    public function path(): string
    {
        return $this->manifest->pluginDir;
    }

    public function slug(): string
    {
        return $this->manifest->slug;
    }
}
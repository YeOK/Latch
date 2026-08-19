<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

interface PluginInterface
{
    /**
     * Called once at boot for enabled, compatible plugins.
     * Register hooks and routes only — do not assume an HTTP user yet.
     * See docs/PLUGINS.md.
     */
    public function register(PluginContext $context): void;
}
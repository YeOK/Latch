<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

/**
 * Registers hook listeners tagged with the owning plugin slug.
 */
final class PluginHookRegistrar
{
    public function __construct(
        private readonly HookRegistry $registry,
        private readonly string $pluginSlug,
    ) {
    }

    public function add(string $hook, callable $callback, int $priority = 10): void
    {
        $this->registry->add($hook, $callback, $priority, $this->pluginSlug);
    }
}
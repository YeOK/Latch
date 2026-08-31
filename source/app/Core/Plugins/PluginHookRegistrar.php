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
 *
 * Only hooks listed in that plugin's plugin.json are accepted.
 */
final class PluginHookRegistrar
{
    public function __construct(
        private readonly HookRegistry $registry,
        private readonly PluginManifest $manifest,
    ) {
    }

    /**
     * @param callable $callback dispatch: void; collect: string|array|null; filter: mixed
     */
    public function add(string $hook, callable $callback, int $priority = 10): void
    {
        if (!in_array($hook, $this->manifest->hooks, true)) {
            // Undeclared hooks are ignored, not an error — list them in plugin.json.
            return;
        }

        $this->registry->add($hook, $callback, $priority, $this->manifest->slug);
    }
}
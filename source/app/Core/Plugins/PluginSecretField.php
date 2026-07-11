<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

/**
 * Secret declared in plugin.json secrets_schema (stored in config/local.php only).
 */
final class PluginSecretField
{
    public function __construct(
        public readonly string $key,
        public readonly string $configPath,
        public readonly string $label,
        public readonly ?string $description = null,
    ) {
    }
}
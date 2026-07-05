<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Loads default config and optional local overrides.
 */
final class Config
{
    private array $config;

    public function __construct(string $configDir)
    {
        $default = require $configDir . '/default.php';
        $localPath = $configDir . '/local.php';

        $local = is_file($localPath) ? require $localPath : [];

        $this->config = array_replace_recursive($default, $local);

        $dbOverride = getenv('LATCH_DB_PATH');
        if (is_string($dbOverride) && $dbOverride !== '') {
            $this->config['database']['path'] = $dbOverride;
        }

        $storageOverride = getenv('LATCH_STORAGE_PATH');
        if (is_string($storageOverride) && $storageOverride !== '') {
            $this->config['paths']['storage'] = $storageOverride;
        }
    }

    public function all(): array
    {
        return $this->config;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function isInstalled(): bool
    {
        $dbPath = $this->get('database.path');

        return is_string($dbPath) && is_file($dbPath);
    }
}
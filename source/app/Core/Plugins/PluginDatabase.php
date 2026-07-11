<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use Latch\Core\Database;
use PDO;

/**
 * Per-plugin SQLite connection (storage/plugins/{slug}/plugin.sqlite).
 */
final class PluginDatabase
{
    public function __construct(
        private readonly Database $db,
        public readonly string $slug,
        public readonly string $path,
    ) {
    }

    public function pdo(): PDO
    {
        return $this->db->pdo();
    }

    public function database(): Database
    {
        return $this->db;
    }
}
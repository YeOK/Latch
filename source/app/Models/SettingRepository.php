<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Models;

use Latch\Core\Database;

/**
 * Key/value site settings stored in SQLite.
 *
 * Values are loaded once per request (full table) so layout/guards that touch
 * many keys do not issue one SELECT each.
 */
final class SettingRepository
{
    /** @var array<string, string>|null null = not hydrated yet */
    private ?array $cache = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $this->hydrate();
        if (!array_key_exists($key, $this->cache ?? [])) {
            return $default;
        }

        return $this->cache[$key];
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO settings (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute(['key' => $key, 'value' => $value]);

        $this->hydrate();
        $this->cache[$key] = $value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function setBool(string $key, bool $value): void
    {
        $this->set($key, $value ? '1' : '0');
    }

    /**
     * Drop the request cache (e.g. after external bulk SQL). Rarely needed.
     */
    public function clearCache(): void
    {
        $this->cache = null;
    }

    private function hydrate(): void
    {
        if ($this->cache !== null) {
            return;
        }

        $this->cache = [];
        $stmt = $this->db->pdo()->query('SELECT key, value FROM settings');
        if ($stmt === false) {
            return;
        }

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $k = (string) ($row['key'] ?? '');
            if ($k === '') {
                continue;
            }
            $this->cache[$k] = (string) ($row['value'] ?? '');
        }
    }
}

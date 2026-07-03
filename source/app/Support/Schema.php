<?php

declare(strict_types=1);

namespace Latch\Support;

use Latch\Core\Database;

/**
 * Lightweight schema capability checks (cached) for optional migrations.
 */
final class Schema
{
    /** @var array<string, bool> */
    private static array $columnCache = [];

    public static function tableHasColumn(Database $db, string $table, string $column): bool
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
            return false;
        }

        $key = spl_object_id($db->pdo()) . ':' . $table . '.' . $column;
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }

        $stmt = $db->pdo()->query('PRAGMA table_info(' . $table . ')');
        $found = false;
        foreach ($stmt->fetchAll() as $row) {
            if (($row['name'] ?? '') === $column) {
                $found = true;
                break;
            }
        }

        self::$columnCache[$key] = $found;

        return $found;
    }

    public static function postsHaveTrashQueue(Database $db): bool
    {
        return self::tableHasColumn($db, 'posts', 'trashed_at');
    }
}
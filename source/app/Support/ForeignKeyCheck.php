<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

/**
 * Classifies SQLite foreign_key_check rows — author orphans after account retention purge are expected.
 */
final class ForeignKeyCheck
{
    /** Child tables that may reference a missing users row after hard-purge of self-deleted accounts. */
    private const ALLOWED_USER_ORPHAN_CHILD_TABLES = [
        'posts',
        'topics',
        'post_revisions',
    ];

    public static function isAllowedUserOrphan(string $childTable, string $parentTable): bool
    {
        return $parentTable === 'users'
            && in_array($childTable, self::ALLOWED_USER_ORPHAN_CHILD_TABLES, true);
    }

    /**
     * @param list<array<string, mixed>> $violations
     * @return array{unexpected: list<array<string, mixed>>, allowed_orphans: int}
     */
    public static function partitionViolations(array $violations): array
    {
        $unexpected = [];
        $allowedOrphans = 0;

        foreach ($violations as $row) {
            if (self::isAllowedUserOrphan(
                (string) ($row['table'] ?? ''),
                (string) ($row['parent'] ?? ''),
            )) {
                $allowedOrphans++;

                continue;
            }

            $unexpected[] = $row;
        }

        return [
            'unexpected' => $unexpected,
            'allowed_orphans' => $allowedOrphans,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function formatViolation(array $row): string
    {
        return sprintf(
            'table=%s rowid=%s parent=%s fk=%s',
            (string) ($row['table'] ?? ''),
            (string) ($row['rowid'] ?? ''),
            (string) ($row['parent'] ?? ''),
            (string) ($row['fkid'] ?? ''),
        );
    }
}
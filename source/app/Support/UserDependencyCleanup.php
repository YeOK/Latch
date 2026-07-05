<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

use PDO;

/**
 * Deletes rows that reference users — used by purge-users and daily cron orphan sweep.
 *
 * Single source of truth so operators do not rely on FK CASCADE alone (restore, manual SQL,
 * or legacy deletes can leave orphans that fail foreign_key_check).
 */
final class UserDependencyCleanup
{
    /** @var list<array{0: string, 1: string}> table, user_id column */
    private const USER_ID_TABLES = [
        ['oauth_refresh_tokens', 'user_id'],
        ['oauth_access_tokens', 'user_id'],
        ['oauth_authorization_codes', 'user_id'],
        ['email_verifications', 'user_id'],
        ['password_resets', 'user_id'],
        ['email_change_requests', 'user_id'],
        ['user_sessions', 'user_id'],
        ['user_recovery_codes', 'user_id'],
        ['user_notifications', 'user_id'],
        ['topic_watches', 'user_id'],
        ['topic_reads', 'user_id'],
        ['post_reactions', 'user_id'],
        ['reputation_queue', 'user_id'],
        ['reputation_snapshots', 'user_id'],
        ['oidc_identities', 'user_id'],
        ['dm_participants', 'user_id'],
        ['user_warnings', 'user_id'],
    ];

    public function deleteForUser(PDO $pdo, int $userId): void
    {
        $bind = ['id' => $userId];

        foreach (self::USER_ID_TABLES as [$table, $column]) {
            $this->deleteWhere($pdo, $table, "{$column} = :id", $bind);
        }

        $this->deleteWhere($pdo, 'dm_conversations', 'user_low = :id OR user_high = :id', $bind);
        $this->deleteWhere($pdo, 'user_blocks', 'blocker_id = :id OR blocked_id = :id', $bind);
        $this->deleteWhere($pdo, 'user_warnings', 'issued_by = :id', $bind);
        $this->deleteWhere($pdo, 'post_revisions', 'editor_id = :id', $bind);
        $this->nullifyColumn($pdo, 'oauth_clients', 'created_by_user_id', 'created_by_user_id = :id', $bind);
        $this->nullifyColumn($pdo, 'posts', 'trashed_by_user_id', 'trashed_by_user_id = :id', $bind);
    }

    /**
     * Remove rows pointing at deleted users. Safe to run daily (idempotent).
     *
     * @return array<string, int> rows deleted per table (omits tables with zero)
     */
    public function pruneOrphans(PDO $pdo): array
    {
        $removed = [];

        foreach (self::USER_ID_TABLES as [$table, $column]) {
            $count = $this->deleteOrphansOnColumn($pdo, $table, $column);
            if ($count > 0) {
                $removed[$table] = $count;
            }
        }

        $dm = $this->deleteWhere(
            $pdo,
            'dm_conversations',
            'user_low NOT IN (SELECT id FROM users) OR user_high NOT IN (SELECT id FROM users)',
            [],
        );
        if ($dm > 0) {
            $removed['dm_conversations'] = $dm;
        }

        $blocks = $this->deleteWhere(
            $pdo,
            'user_blocks',
            'blocker_id NOT IN (SELECT id FROM users) OR blocked_id NOT IN (SELECT id FROM users)',
            [],
        );
        if ($blocks > 0) {
            $removed['user_blocks'] = $blocks;
        }

        $warnings = $this->deleteWhere(
            $pdo,
            'user_warnings',
            'issued_by NOT IN (SELECT id FROM users)',
            [],
        );
        if ($warnings > 0) {
            $removed['user_warnings_issued_by'] = $warnings;
        }

        $revisions = $this->deleteWhere(
            $pdo,
            'post_revisions',
            'editor_id NOT IN (SELECT id FROM users)',
            [],
        );
        if ($revisions > 0) {
            $removed['post_revisions'] = $revisions;
        }

        $clients = $this->nullifyColumn(
            $pdo,
            'oauth_clients',
            'created_by_user_id',
            'created_by_user_id IS NOT NULL AND created_by_user_id NOT IN (SELECT id FROM users)',
            [],
        );
        if ($clients > 0) {
            $removed['oauth_clients'] = $clients;
        }

        $trash = $this->nullifyColumn(
            $pdo,
            'posts',
            'trashed_by_user_id',
            'trashed_by_user_id IS NOT NULL AND trashed_by_user_id NOT IN (SELECT id FROM users)',
            [],
        );
        if ($trash > 0) {
            $removed['posts_trashed_by'] = $trash;
        }

        return $removed;
    }

    /**
     * @param array<string, int|string> $bind
     */
    private function deleteWhere(PDO $pdo, string $table, string $where, array $bind): int
    {
        if (!$this->tableExists($pdo, $table)) {
            return 0;
        }

        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE {$where}");
        $stmt->execute($bind);

        return $stmt->rowCount();
    }

    private function deleteOrphansOnColumn(PDO $pdo, string $table, string $column): int
    {
        return $this->deleteWhere(
            $pdo,
            $table,
            "{$column} NOT IN (SELECT id FROM users)",
            [],
        );
    }

    /**
     * @param array<string, int|string> $bind
     */
    private function nullifyColumn(PDO $pdo, string $table, string $column, string $where, array $bind): int
    {
        if (!$this->tableExists($pdo, $table) || !$this->columnExists($pdo, $table, $column)) {
            return 0;
        }

        $stmt = $pdo->prepare("UPDATE {$table} SET {$column} = NULL WHERE {$where}");
        $stmt->execute($bind);

        return $stmt->rowCount();
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];

        $cacheKey = spl_object_id($pdo) . ':' . $table . ':' . $column;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        if (!$this->tableExists($pdo, $table)) {
            return $cache[$cacheKey] = false;
        }

        $stmt = $pdo->query("PRAGMA table_info({$table})");
        if ($stmt === false) {
            return $cache[$cacheKey] = false;
        }

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (($row['name'] ?? '') === $column) {
                return $cache[$cacheKey] = true;
            }
        }

        return $cache[$cacheKey] = false;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        static $cache = [];

        $cacheKey = spl_object_id($pdo) . ':' . $table;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $stmt = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1"
        );
        $stmt->execute(['name' => $table]);
        $cache[$cacheKey] = (bool) $stmt->fetchColumn();

        return $cache[$cacheKey];
    }
}
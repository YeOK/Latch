<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Per-board permission levels. Higher viewer roles satisfy lower requirements.
 */
final class BoardAcl
{
    public const ROLE_GUEST = 'guest';
    public const ROLE_MEMBER = 'member';
    public const ROLE_MOD = 'mod';
    public const ROLE_ADMIN = 'admin';

    public const ACTION_READ = 'read';
    public const ACTION_TOPIC = 'topic';
    public const ACTION_REPLY = 'reply';

    /** @var array<string, int> */
    private const LEVELS = [
        self::ROLE_GUEST => 0,
        self::ROLE_MEMBER => 1,
        self::ROLE_MOD => 2,
        self::ROLE_ADMIN => 3,
    ];

    /** @var array<string, list<string>> */
    private const ROLE_OPTIONS = [
        self::ACTION_READ => [self::ROLE_GUEST, self::ROLE_MEMBER, self::ROLE_MOD, self::ROLE_ADMIN],
        self::ACTION_TOPIC => [self::ROLE_MEMBER, self::ROLE_MOD, self::ROLE_ADMIN],
        self::ACTION_REPLY => [self::ROLE_GUEST, self::ROLE_MEMBER, self::ROLE_MOD, self::ROLE_ADMIN],
    ];

    /** @var array<string, string> */
    public const ROLE_LABELS = [
        self::ROLE_GUEST => 'Everyone',
        self::ROLE_MEMBER => 'Signed-in members',
        self::ROLE_MOD => 'Moderators+',
        self::ROLE_ADMIN => 'Admins only',
    ];

    /**
     * @return list<string>
     */
    public static function optionsFor(string $action): array
    {
        return self::ROLE_OPTIONS[$action] ?? self::ROLE_OPTIONS[self::ACTION_READ];
    }

    public static function normalize(string $action, string $role): string
    {
        $role = strtolower(trim($role));
        $allowed = self::optionsFor($action);

        return in_array($role, $allowed, true) ? $role : $allowed[0];
    }

    public static function viewerLevel(bool $loggedIn, ?string $userRole): int
    {
        if (!$loggedIn) {
            return self::LEVELS[self::ROLE_GUEST];
        }

        return self::LEVELS[$userRole] ?? self::LEVELS[self::ROLE_MEMBER];
    }

    public static function requiredLevel(array $board, string $action): int
    {
        $column = 'acl_' . $action;
        $role = (string) ($board[$column] ?? self::defaultFor($action));

        return self::LEVELS[$role] ?? self::LEVELS[self::defaultFor($action)];
    }

    public static function defaultFor(string $action): string
    {
        return match ($action) {
            self::ACTION_READ => self::ROLE_GUEST,
            self::ACTION_TOPIC, self::ACTION_REPLY => self::ROLE_MEMBER,
            default => self::ROLE_MEMBER,
        };
    }

    public static function allows(
        array $board,
        string $action,
        bool $loggedIn,
        ?string $userRole,
        bool $membersOnly,
        ?int $reputationRank = null,
    ): bool {
        // Site-wide members_only blocks all guests before per-board acl_read is evaluated.
        if ($membersOnly && !$loggedIn) {
            return false;
        }

        if (self::viewerLevel($loggedIn, $userRole) < self::requiredLevel($board, $action)) {
            return false;
        }

        return self::satisfiesMinRank($board, $action, $loggedIn, $userRole, $reputationRank);
    }

    public static function minRankFor(array $board, string $action): ?int
    {
        $column = match ($action) {
            self::ACTION_READ => 'min_rank_read',
            self::ACTION_TOPIC => 'min_rank_topic',
            self::ACTION_REPLY => 'min_rank_reply',
            default => null,
        };

        if ($column === null) {
            return null;
        }

        $value = $board[$column] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        $rank = (int) $value;

        return $rank >= 1 && $rank <= 5 ? $rank : null;
    }

    public static function satisfiesMinRank(
        array $board,
        string $action,
        bool $loggedIn,
        ?string $userRole,
        ?int $reputationRank,
    ): bool {
        $required = self::minRankFor($board, $action);
        if ($required === null) {
            return true;
        }

        if (!$loggedIn) {
            return false;
        }

        $level = self::viewerLevel(true, $userRole);
        if ($level >= self::LEVELS[self::ROLE_MOD]) {
            return true;
        }

        return ($reputationRank ?? 0) >= $required;
    }

    /**
     * @return list<string>
     */
    public static function readableRoles(bool $loggedIn, ?string $userRole): array
    {
        if (!$loggedIn) {
            return [self::ROLE_GUEST];
        }

        $viewerLevel = self::viewerLevel(true, $userRole);
        $roles = [];
        foreach (self::LEVELS as $role => $level) {
            if ($level <= $viewerLevel) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * SQL fragment for guest/member board visibility in RSS, search, and sitemap.
     * Role names come from readableRoles() only — never from user input.
     */
    public static function sqlBoardReadFilter(bool $loggedIn, ?string $userRole): string
    {
        $roles = self::readableRoles($loggedIn, $userRole);
        if ($roles === []) {
            return ' AND 1=0';
        }

        $quoted = array_map(static fn (string $role): string => "'" . $role . "'", $roles);

        return ' AND b.acl_read IN (' . implode(',', $quoted) . ')';
    }

    public static function isStaffOnlyTopics(array $board): bool
    {
        return self::requiredLevel($board, self::ACTION_TOPIC) >= self::LEVELS[self::ROLE_MOD];
    }

    public static function isMembersOnlyRead(array $board): bool
    {
        return self::requiredLevel($board, self::ACTION_READ) >= self::LEVELS[self::ROLE_MEMBER];
    }

    public static function isPublicRead(array $board): bool
    {
        return self::requiredLevel($board, self::ACTION_READ) === self::LEVELS[self::ROLE_GUEST];
    }
}
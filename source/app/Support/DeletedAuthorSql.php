<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

/**
 * SQL fragments for forum content whose author row was purged after self-delete retention.
 */
final class DeletedAuthorSql
{
    public const LABEL = '[deleted]';

    public static function authorName(string $userAlias = 'u'): string
    {
        return self::usernameAlias('author_name', $userAlias);
    }

    public static function usernameAlias(string $columnAlias, string $userAlias = 'u'): string
    {
        return "COALESCE({$userAlias}.username, '" . self::LABEL . "') AS {$columnAlias}";
    }
}
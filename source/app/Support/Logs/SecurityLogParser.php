<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support\Logs;

final class SecurityLogParser
{
    /**
     * @param array{event?: string, ip?: string, username?: string, since?: string, until?: string} $filters
     */
    public function matchesLine(string $line, array $filters): bool
    {
        $parsed = $this->parseLine($line);
        if ($parsed === null) {
            return false;
        }

        if (isset($filters['event']) && ($parsed['event'] ?? '') !== $filters['event']) {
            return false;
        }

        if (isset($filters['ip']) && ($parsed['ip'] ?? '') !== $filters['ip']) {
            return false;
        }

        if (isset($filters['username'])) {
            $username = strtolower((string) ($parsed['username'] ?? ''));
            if ($username !== strtolower($filters['username'])) {
                return false;
            }
        }

        if (isset($filters['since']) || isset($filters['until'])) {
            $ts = strtotime((string) ($parsed['ts'] ?? ''));
            if ($ts === false) {
                return false;
            }

            if (isset($filters['since'])) {
                $since = strtotime($filters['since']);
                if ($since !== false && $ts < $since) {
                    return false;
                }
            }

            if (isset($filters['until'])) {
                $until = strtotime($filters['until']);
                if ($until !== false && $ts > $until) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array{ts: ?string, event: ?string, ip: ?string, username: ?string, user_id: mixed, meta: mixed, parse_error: bool}|null
     */
    public function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [
                'ts' => null,
                'event' => null,
                'ip' => null,
                'username' => null,
                'user_id' => null,
                'meta' => null,
                'parse_error' => true,
            ];
        }

        if (!is_array($decoded)) {
            return null;
        }

        return [
            'ts' => isset($decoded['ts']) ? (string) $decoded['ts'] : null,
            'event' => isset($decoded['event']) ? (string) $decoded['event'] : null,
            'ip' => isset($decoded['ip']) ? (string) $decoded['ip'] : null,
            'username' => isset($decoded['username']) ? (string) $decoded['username'] : null,
            'user_id' => $decoded['user_id'] ?? null,
            'meta' => $decoded['meta'] ?? null,
            'parse_error' => false,
        ];
    }
}
<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * JSON-lines security event log at storage/logs/security.log.
 */
final class SecurityLog
{
    public function __construct(private readonly string $logPath)
    {
        $dir = dirname($logPath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create log directory: ' . $dir);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $event, array $context = []): void
    {
        $entry = [
            'ts' => gmdate('c'),
            'event' => $event,
            'ip' => $context['ip'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'username' => $context['username'] ?? null,
            'target_type' => $context['target_type'] ?? null,
            'target_id' => $context['target_id'] ?? null,
            'meta' => $this->mergeMeta($context),
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Extra context keys (e.g. registration `reason`) are stored in meta so they
     * are not silently dropped. Explicit `meta` wins on key collision.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function mergeMeta(array $context): ?array
    {
        $reserved = ['ip', 'user_id', 'username', 'target_type', 'target_id', 'meta', 'ts', 'event'];
        $meta = [];

        foreach ($context as $key => $value) {
            if (!in_array($key, $reserved, true)) {
                $meta[$key] = $value;
            }
        }

        $explicit = $context['meta'] ?? null;
        if (is_array($explicit)) {
            $meta = array_merge($meta, $explicit);
        } elseif ($explicit !== null) {
            $meta['value'] = $explicit;
        }

        return $meta === [] ? null : $meta;
    }
}
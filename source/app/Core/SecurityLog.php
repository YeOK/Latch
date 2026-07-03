<?php

declare(strict_types=1);

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
            'meta' => $context['meta'] ?? null,
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }
}
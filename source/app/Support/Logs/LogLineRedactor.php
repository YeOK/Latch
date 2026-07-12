<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support\Logs;

final class LogLineRedactor
{
    public function __construct(private readonly bool $maskEmails = false)
    {
    }

    public function redact(string $line): string
    {
        $line = preg_replace(
            '/\bpassword=([^\s&"\']+)/i',
            'password=[REDACTED]',
            $line,
        ) ?? $line;

        $line = preg_replace(
            '/"password"\s*:\s*"[^"]*"/i',
            '"password":"[REDACTED]"',
            $line,
        ) ?? $line;

        $line = preg_replace(
            '/Authorization:\s*Bearer\s+\S+/i',
            'Authorization: Bearer [REDACTED]',
            $line,
        ) ?? $line;

        $line = preg_replace(
            '/Cookie:\s*.+/i',
            'Cookie: [REDACTED]',
            $line,
        ) ?? $line;

        $line = preg_replace(
            '/\b(token|reset_token|client_secret)=([^\s&"\']+)/i',
            '$1=[REDACTED]',
            $line,
        ) ?? $line;

        $line = preg_replace(
            '/"(token|reset_token|client_secret)"\s*:\s*"[^"]*"/i',
            '"$1":"[REDACTED]"',
            $line,
        ) ?? $line;

        if ($this->maskEmails) {
            $line = preg_replace_callback(
                '/\b([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/',
                static fn (array $m): string => $m[1] . '***@' . $m[2],
                $line,
            ) ?? $line;
        }

        return $line;
    }
}
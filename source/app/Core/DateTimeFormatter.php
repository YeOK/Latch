<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use DateTimeImmutable;
use Exception;

/**
 * Formats ISO-8601 timestamps for display.
 */
final class DateTimeFormatter
{
    public function format(?string $iso, string $pattern = 'Y-m-d H:i'): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($iso))->format($pattern);
        } catch (Exception) {
            return str_replace('T', ' ', substr($iso, 0, 16));
        }
    }

    public function formatDate(?string $iso): string
    {
        return $this->format($iso, 'Y-m-d');
    }
}
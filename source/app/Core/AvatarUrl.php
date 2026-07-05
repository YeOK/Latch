<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Gravatar URLs for member avatars (no arbitrary external image hosts in core).
 */
final class AvatarUrl
{
    public function gravatarUrl(string $email, int $size = 48): string
    {
        $hash = md5(strtolower(trim($email)));
        $size = max(16, min(512, $size));

        return 'https://www.gravatar.com/avatar/' . $hash . '?s=' . $size . '&d=identicon';
    }
}
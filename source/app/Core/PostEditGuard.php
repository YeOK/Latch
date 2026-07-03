<?php

declare(strict_types=1);

namespace Latch\Core;

/**
 * Who may edit whose posts when acting as staff.
 */
final class PostEditGuard
{
    public static function modMayEditAuthor(int $authorId, string $authorRole): bool
    {
        if ($authorId === Auth::FOUNDER_USER_ID) {
            return false;
        }

        return $authorRole !== Auth::ROLE_ADMIN;
    }
}
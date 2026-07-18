<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

/**
 * Mutable context passed to profile.before_save hooks.
 */
final class ProfileSaveContext
{
    public ?string $rejectReason = null;

    /**
     * When true, core writes users.avatar_url after hooks (null clears the column).
     */
    public bool $updateAvatarUrl = false;

    /**
     * Stored custom avatar URL when {@see $updateAvatarUrl} is true.
     */
    public ?string $avatarUrl = null;

    /**
     * @param array<string, mixed> $user
     * @param string|null $avatarUrlInput Raw profile form field when present; null if not submitted
     */
    public function __construct(
        public string $bio,
        public readonly array $user,
        public readonly ?string $avatarUrlInput = null,
    ) {
    }

    public function reject(string $reason): void
    {
        $this->rejectReason = $reason;
    }
}
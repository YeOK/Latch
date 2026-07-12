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
     * @param array<string, mixed> $user
     */
    public function __construct(
        public string $bio,
        public readonly array $user,
    ) {
    }

    public function reject(string $reason): void
    {
        $this->rejectReason = $reason;
    }
}
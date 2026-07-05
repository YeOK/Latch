<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Oidc;

/**
 * Normalized identity from an OIDC/OAuth provider callback.
 */
final class OidcProviderProfile
{
    public function __construct(
        public readonly string $subject,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $preferredUsername,
        public readonly ?string $displayName,
    ) {
    }
}
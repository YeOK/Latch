<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * GDPR cookie-consent checks for optional third-party content (e.g. Gravatar).
 */
final class CookieConsentGate
{
    public const CONSENT_COOKIE = 'latch_cookie_consent';

    public const CONSENT_ACCEPTED = 'accepted';

    public static function allowsThirdPartyContent(bool $gdprEnabled, ?string $consentValue): bool
    {
        if (!$gdprEnabled) {
            return true;
        }

        return $consentValue === self::CONSENT_ACCEPTED;
    }
}
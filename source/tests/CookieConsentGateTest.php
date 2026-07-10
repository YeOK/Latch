<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\CookieConsentGate;
use PHPUnit\Framework\TestCase;

final class CookieConsentGateTest extends TestCase
{
    public function testThirdPartyAllowedWhenGdprDisabled(): void
    {
        $this->assertTrue(CookieConsentGate::allowsThirdPartyContent(false, null));
        $this->assertTrue(CookieConsentGate::allowsThirdPartyContent(false, 'rejected'));
    }

    public function testThirdPartyBlockedUntilConsentAccepted(): void
    {
        $this->assertFalse(CookieConsentGate::allowsThirdPartyContent(true, null));
        $this->assertFalse(CookieConsentGate::allowsThirdPartyContent(true, ''));
        $this->assertFalse(CookieConsentGate::allowsThirdPartyContent(true, 'rejected'));
    }

    public function testThirdPartyAllowedAfterConsentAccepted(): void
    {
        $this->assertTrue(
            CookieConsentGate::allowsThirdPartyContent(true, CookieConsentGate::CONSENT_ACCEPTED),
        );
    }
}
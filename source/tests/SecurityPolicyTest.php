<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Config;
use Latch\Core\Database;
use Latch\Core\Request;
use Latch\Core\SecurityPolicy;
use Latch\Core\Turnstile;
use Latch\Models\SettingRepository;
use PHPUnit\Framework\TestCase;

final class SecurityPolicyTest extends TestCase
{
    private SettingRepository $settings;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $pdo = $db->pdo();
        $pdo->exec(
            'CREATE TABLE settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
             )'
        );
        $this->settings = new SettingRepository($db);
    }

    public function testStandardModeUsesIndividualToggles(): void
    {
        $policy = $this->policy(
            turnstileSiteKey: 'site-key',
            turnstileSecretKey: 'secret-key',
        );

        $this->assertSame(SecurityPolicy::MODE_STANDARD, $policy->mode());
        $this->assertFalse($policy->loginTurnstileRequired());
        $this->assertFalse($policy->modTwoFactorRequired());
        $this->assertSame(['admin'], $policy->totpRequiredRoles());

        $this->settings->setBool('login_turnstile_enabled', true);
        $this->settings->setBool('totp_required_mod', true);

        $this->assertTrue($policy->loginTurnstileRequired());
        $this->assertTrue($policy->modTwoFactorRequired());
        $this->assertSame(['admin', 'mod'], $policy->totpRequiredRoles());
    }

    public function testHighModeEnforcesHardeningOptions(): void
    {
        $this->settings->set('security_mode', SecurityPolicy::MODE_HIGH);

        $policy = $this->policy(
            turnstileSiteKey: 'site-key',
            turnstileSecretKey: 'secret-key',
        );

        $this->assertTrue($policy->isHigh());
        $this->assertTrue($policy->loginTurnstileRequired());
        $this->assertTrue($policy->registrationTurnstileRequired());
        $this->assertTrue($policy->modTwoFactorRequired());
        $this->assertSame(['admin', 'mod'], $policy->totpRequiredRoles());
    }

    public function testTurnstileRequirementsStayOffWhenNotConfigured(): void
    {
        $this->settings->set('security_mode', SecurityPolicy::MODE_HIGH);
        $this->settings->setBool('login_turnstile_enabled', true);

        $policy = $this->policy();

        $this->assertFalse($policy->loginTurnstileRequired());
        $this->assertFalse($policy->registrationTurnstileRequired());
        $this->assertTrue($policy->modTwoFactorRequired());
    }

    private function policy(
        string $turnstileSiteKey = '',
        string $turnstileSecretKey = '',
    ): SecurityPolicy {
        $config = new Config(LATCH_ROOT . '/config');

        return new SecurityPolicy(
            $this->settings,
            $config,
            new Turnstile($turnstileSiteKey, $turnstileSecretKey),
            new Request($config),
        );
    }
}
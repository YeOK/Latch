<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use Latch\Models\SettingRepository;

/**
 * Site security posture from admin settings (security mode + optional hardening toggles).
 */
final class SecurityPolicy
{
    public const MODE_STANDARD = 'standard';
    public const MODE_HIGH = 'high';

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly Config $config,
        private readonly Turnstile $turnstile,
        private readonly Request $request,
    ) {
    }

    public function mode(): string
    {
        $mode = strtolower(trim($this->settings->get('security_mode', self::MODE_STANDARD)));

        return $mode === self::MODE_HIGH ? self::MODE_HIGH : self::MODE_STANDARD;
    }

    public function isHigh(): bool
    {
        return $this->mode() === self::MODE_HIGH;
    }

    public function turnstileConfigured(): bool
    {
        return $this->turnstile->isConfigured();
    }

    public function turnstileSiteKey(): string
    {
        return $this->turnstile->siteKey();
    }

    public function loginTurnstileRequired(): bool
    {
        if (!$this->turnstileConfigured()) {
            return false;
        }

        return $this->isHigh() || $this->settings->getBool('login_turnstile_enabled', false);
    }

    public function registrationTurnstileRequired(): bool
    {
        if (!$this->turnstileConfigured()) {
            return false;
        }

        return $this->isHigh() || $this->settings->getBool('registration_turnstile_enabled', true);
    }

    public function loginTurnstileValid(): bool
    {
        if (!$this->loginTurnstileRequired()) {
            return true;
        }

        return $this->verifyTurnstileResponse();
    }

    public function registrationTurnstileValid(): bool
    {
        if (!$this->registrationTurnstileRequired()) {
            return true;
        }

        return $this->verifyTurnstileResponse();
    }

    public function modTwoFactorRequired(): bool
    {
        return $this->isHigh() || $this->settings->getBool('totp_required_mod', false);
    }

    /**
     * @return list<string>
     */
    public function totpRequiredRoles(): array
    {
        $roles = $this->config->get('security.totp_required_roles', ['admin']);
        if (!is_array($roles)) {
            $roles = ['admin'];
        }

        $roles = array_values(array_filter(array_map(
            static fn (mixed $role): string => is_string($role) ? strtolower(trim($role)) : '',
            $roles,
        )));

        if ($roles === []) {
            $roles = ['admin'];
        }

        if ($this->modTwoFactorRequired() && !in_array('mod', $roles, true)) {
            $roles[] = 'mod';
        }

        return array_values(array_unique($roles));
    }

    private function verifyTurnstileResponse(): bool
    {
        $token = (string) $this->request->input('cf-turnstile-response', '');

        return $this->turnstile->verify($token, $this->request->ip());
    }
}
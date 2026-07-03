<?php

declare(strict_types=1);

namespace Latch\Core;

use Latch\Models\SettingRepository;

/**
 * Honeypot, Turnstile, and rate limits for new account signups.
 */
final class RegistrationGuard
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly RateLimiter $rateLimiter,
        private readonly Turnstile $turnstile,
        private readonly SecurityLog $securityLog,
        private readonly Request $request,
    ) {
    }

    public function honeypotEnabled(): bool
    {
        return $this->settings->getBool('registration_honeypot_enabled', true);
    }

    public function honeypotTriggered(): bool
    {
        if (!$this->honeypotEnabled()) {
            return false;
        }

        return trim((string) $this->request->input('website', '')) !== '';
    }

    public function turnstileRequired(): bool
    {
        return $this->settings->getBool('registration_turnstile_enabled', true)
            && $this->turnstile->isConfigured();
    }

    public function turnstileSiteKey(): string
    {
        return $this->turnstile->siteKey();
    }

    public function turnstileValid(): bool
    {
        if (!$this->turnstileRequired()) {
            return true;
        }

        $token = (string) $this->request->input('cf-turnstile-response', '');

        return $this->turnstile->verify($token, $this->request->ip());
    }

    public function tooManyAttempts(int $maxAttempts = 3, int $windowMinutes = 60): bool
    {
        return $this->rateLimiter->tooManyRegistrations($this->request->ip(), $maxAttempts, $windowMinutes);
    }

    public function recordAttempt(bool $success): void
    {
        $this->rateLimiter->recordRegistrationAttempt($this->request->ip(), $success);
    }

    public function logBlocked(string $reason): void
    {
        $this->securityLog->log('registration_blocked', [
            'ip' => $this->request->ip(),
            'reason' => $reason,
            'username' => trim((string) $this->request->input('username', '')),
        ]);
    }
}
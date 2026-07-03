<?php

declare(strict_types=1);

namespace Latch\Core;

/**
 * Cloudflare Turnstile verification for registration (and other forms later).
 */
final class Turnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private readonly string $siteKey,
        private readonly string $secretKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->siteKey !== '' && $this->secretKey !== '';
    }

    public function siteKey(): string
    {
        return $this->siteKey;
    }

    public function verify(string $token, string $remoteIp): bool
    {
        // Fail open when Turnstile keys are unset (local dev). RegistrationGuard only
        // requires verification when isConfigured() is true.
        if (!$this->isConfigured()) {
            return true;
        }

        $token = trim($token);
        if ($token === '') {
            return false;
        }

        $body = http_build_query([
            'secret' => $this->secretKey,
            'response' => $token,
            'remoteip' => $remoteIp,
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents(self::VERIFY_URL, false, $context);
        if ($raw === false) {
            return false;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return ($data['success'] ?? false) === true;
    }
}
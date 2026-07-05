<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Encrypts small secrets (e.g. TOTP keys) at rest using libsodium secretbox.
 */
final class SecretCipher
{
    public function __construct(
        private readonly Config $config,
        /** Raw 32-byte key for bootstrap re-wrap before local.php is updated. */
        private readonly ?string $overrideKey = null,
    ) {
    }

    public function hasConfiguredKey(): bool
    {
        return $this->configuredKey() !== null;
    }

    public function encrypt(string $plaintext): string
    {
        self::requireSodium();
        $key = $this->key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $encoded): ?string
    {
        self::requireSodium();
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->configuredKey());
        if ($plain !== false) {
            return $plain;
        }

        // Legacy: secret may still be wrapped with the derived key if bootstrap wrote
        // encryption_key before re-wrapping (or re-wrap failed).
        if ($this->configuredKey() !== null) {
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->derivedKey());
            if ($plain !== false) {
                return $plain;
            }
        }

        return null;
    }

    private function key(): string
    {
        if ($this->overrideKey !== null) {
            return $this->overrideKey;
        }

        $configured = $this->configuredKey();
        if ($configured === null) {
            throw new \RuntimeException(
                'Set security.encryption_key in config/local.php before encrypting secrets (required for two-factor authentication).',
            );
        }

        return $configured;
    }

    private function configuredKey(): ?string
    {
        $configured = trim((string) $this->config->get('security.encryption_key', ''));
        if ($configured === '') {
            return null;
        }

        $decoded = base64_decode($configured, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return null;
        }

        return $decoded;
    }

    /**
     * Legacy decrypt-only fallback when operators enabled TOTP before encryption_key was required.
     */
    private function derivedKey(): string
    {
        self::requireSodium();
        $dbPath = (string) $this->config->get('database.path', 'latch');
        $siteUrl = (string) $this->config->get('site.url', 'latch');

        return sodium_crypto_generichash(
            'latch-totp:' . $dbPath . ':' . $siteUrl,
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
        );
    }

    private static function requireSodium(): void
    {
        if (function_exists('sodium_crypto_secretbox')) {
            return;
        }

        throw new \RuntimeException(
            'PHP sodium extension is required for two-factor authentication (install php-sodium, then restart the web server).',
        );
    }
}
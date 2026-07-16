<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use Latch\Models\RecoveryCodeRepository;
use Latch\Models\UserRepository;

final class TwoFactor
{
    public function __construct(
        private readonly Config $config,
        private readonly SecurityPolicy $securityPolicy,
        private readonly UserRepository $users,
        private readonly RecoveryCodeRepository $recoveryCodes,
        private readonly SecretCipher $cipher,
        private readonly Totp $totp,
    ) {
    }

    public function isEnabled(array $user): bool
    {
        return ($user['totp_enabled_at'] ?? null) !== null && ($user['totp_secret_enc'] ?? null) !== null;
    }

    public function isMandatory(array $user): bool
    {
        return in_array((string) ($user['role'] ?? ''), $this->securityPolicy->totpRequiredRoles(), true);
    }

    public function mustEnroll(array $user): bool
    {
        return $this->isMandatory($user) && !$this->isEnabled($user);
    }

    public function needsChallenge(array $user): bool
    {
        return $this->isEnabled($user);
    }

    public function encryptionReady(): bool
    {
        return $this->cipher->hasConfiguredKey();
    }

    public function generateSecret(): string
    {
        return $this->totp->generateSecret();
    }

    public function provisioningUri(array $user, string $secret): string
    {
        $issuer = (string) $this->config->get('site.name', 'Latch');

        return $this->totp->provisioningUri($secret, (string) $user['username'], $issuer);
    }

    public function verifyCode(array $user, string $code): bool
    {
        $secret = $this->decryptSecret($user);
        if ($secret === null) {
            return false;
        }

        return $this->totp->verify($secret, $code);
    }

    public function verifyRecoveryCode(int $userId, string $code): bool
    {
        return $this->recoveryCodes->verifyAndConsume($userId, $code);
    }

    public function enable(int $userId, string $secret, string $code): bool
    {
        if (!$this->totp->verify($secret, $code)) {
            return false;
        }

        $this->users->enableTotp($userId, $this->cipher->encrypt($secret));

        return true;
    }

    /**
     * @return list<string>
     */
    public function issueRecoveryCodes(int $userId): array
    {
        return $this->recoveryCodes->replaceForUser($userId);
    }

    public function disable(int $userId): void
    {
        $this->users->disableTotp($userId);
        $this->recoveryCodes->deleteForUser($userId);
    }

    public function unusedRecoveryCodeCount(int $userId): int
    {
        return $this->recoveryCodes->countUnused($userId);
    }

    private function decryptSecret(array $user): ?string
    {
        $enc = $user['totp_secret_enc'] ?? null;
        if ($enc === null || $enc === '') {
            return null;
        }

        return $this->cipher->decrypt((string) $enc);
    }
}
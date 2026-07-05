<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Models;

use Latch\Core\Database;

final class OidcIdentityRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findByProviderSubject(string $provider, string $subject): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM oidc_identities
             WHERE provider = :provider AND provider_subject = :subject
             LIMIT 1'
        );
        $stmt->execute([
            'provider' => $provider,
            'subject' => $subject,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findForUser(int $userId, string $provider): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM oidc_identities
             WHERE user_id = :user_id AND provider = :provider
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'provider' => $provider,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function link(int $userId, string $provider, string $subject, ?string $email): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO oidc_identities (user_id, provider, provider_subject, email, created_at)
             VALUES (:user_id, :provider, :provider_subject, :email, :created_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'provider' => $provider,
            'provider_subject' => $subject,
            'email' => $email !== null ? strtolower(trim($email)) : null,
            'created_at' => gmdate('c'),
        ]);
    }
}
<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use Latch\Models\UserRepository;
use Latch\Models\UserSessionRepository;

/**
 * Session-backed authentication and authorization checks.
 */
final class Auth
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MOD = 'mod';
    public const ROLE_MEMBER = 'member';
    public const FOUNDER_USER_ID = 1;

    public function __construct(
        private readonly Session $session,
        private readonly UserRepository $users,
        private readonly UserSessionRepository $userSessions,
        private readonly Request $request,
        private readonly Csrf $csrf,
    ) {
    }

    public function user(): ?array
    {
        $id = $this->session->get('user_id');
        if (!is_int($id) && !is_string($id)) {
            return null;
        }

        $sessionId = $this->session->id();
        if ($sessionId !== '' && $this->userSessions->isRevoked($sessionId)) {
            $this->logout();

            return null;
        }

        $user = $this->users->findById((int) $id);
        if ($user === null) {
            $this->logout();

            return null;
        }

        if ($this->users->isDeleted($user)) {
            $this->logout();

            return null;
        }

        if (!$this->users->isBanned($user)) {
            if (($user['banned_at'] ?? null) !== null || ($user['banned_until'] ?? null) !== null) {
                $this->users->clearExpiredBan((int) $user['id']);
                $user = $this->users->findById((int) $id);
                if ($user === null) {
                    $this->logout();

                    return null;
                }
            }
        } else {
            $this->logout();

            return null;
        }

        if ($this->users->isLocked($user)) {
            $this->logout();

            return null;
        }

        // Invalidate sessions created before the latest password change or reset.
        $passwordChangedAt = $user['password_changed_at'] ?? null;
        $sessionPasswordStamp = $this->session->get('password_changed_at');
        if ($passwordChangedAt !== null && $sessionPasswordStamp !== $passwordChangedAt) {
            $this->logout();

            return null;
        }

        if ($sessionId !== '') {
            $this->userSessions->touch($sessionId);
        }

        return $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function login(array $user): void
    {
        $this->session->regenerate();
        $this->csrf->rotate();
        $this->session->set('user_id', (int) $user['id']);
        $this->session->set('password_changed_at', $user['password_changed_at'] ?? null);

        $sessionId = $this->session->id();
        if ($sessionId !== '') {
            $this->userSessions->register(
                $sessionId,
                (int) $user['id'],
                $this->sessionFingerprint(),
                $this->request->ip(),
                $this->request->userAgent(),
            );
        }

        $this->users->touchLogin((int) $user['id']);
    }

    public function logout(): void
    {
        $sessionId = $this->session->id();
        $userId = $this->session->get('user_id');
        if ($sessionId !== '' && (is_int($userId) || is_string($userId))) {
            $this->userSessions->revoke($sessionId, (int) $userId);
        }

        $this->session->forget('user_id');
        $this->session->forget('password_changed_at');
        $this->clearTotpPending();
    }

    public function pendingTotpUser(): ?array
    {
        $id = $this->session->get('totp_pending_user_id');
        if (!is_int($id) && !is_string($id)) {
            return null;
        }

        return $this->users->findById((int) $id);
    }

    public function hasTotpPending(): bool
    {
        return $this->pendingTotpUser() !== null;
    }

    public function beginTotpPending(array $user, bool $setupRequired = false): void
    {
        $this->session->regenerate();
        $this->session->forget('user_id');
        $this->session->forget('password_changed_at');
        $this->session->set('totp_pending_user_id', (int) $user['id']);
        $this->session->set('totp_setup_required', $setupRequired);
    }

    public function isTotpSetupRequired(): bool
    {
        return $this->session->get('totp_setup_required') === true;
    }

    public function completeLoginAfterTotp(array $user): void
    {
        $this->clearTotpPending();
        $this->login($user);
    }

    public function clearTotpPending(): void
    {
        $this->session->forget('totp_pending_user_id');
        $this->session->forget('totp_setup_required');
        $this->session->forget('totp_setup_secret');
    }

    public function isAdmin(): bool
    {
        $user = $this->user();

        return $user !== null && $user['role'] === self::ROLE_ADMIN;
    }

    public function isMod(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user['role'], [self::ROLE_ADMIN, self::ROLE_MOD], true);
    }

    public function requireLogin(): void
    {
        if (!$this->check()) {
            $this->session->flash('error', 'Please sign in to continue.');
            Response::redirect('/login');
        }
    }

    public function requireAdmin(): void
    {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            Response::forbidden('Admin access required.');
        }

        $user = $this->user();
        if ($user !== null && ($user['totp_enabled_at'] ?? null) === null) {
            $this->session->flash('error', 'Administrators must enable two-factor authentication.');
            Response::redirect('/profile/2fa');
        }
    }

    public function requireMod(): void
    {
        $this->requireLogin();
        if (!$this->isMod()) {
            Response::forbidden('Moderator access required.');
        }
    }

    public function sessionFingerprint(): string
    {
        return hash('sha256', $this->request->userAgent() . '|' . $this->request->ip());
    }
}
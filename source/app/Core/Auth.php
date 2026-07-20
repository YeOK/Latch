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

    private const SESSION_STEPUP_AT = 'staff_stepup_at';
    private const SESSION_STEPUP_RETURN = 'staff_stepup_return';

    /** @var array<string, mixed>|null Resolved once per request after successful auth. */
    private ?array $resolvedUser = null;

    private bool $userResolved = false;

    public function __construct(
        private readonly Session $session,
        private readonly UserRepository $users,
        private readonly UserSessionRepository $userSessions,
        private readonly Request $request,
        private readonly Csrf $csrf,
        private readonly ?TwoFactor $twoFactor = null,
        private readonly ?Config $config = null,
        private readonly ?SecurityLog $securityLog = null,
        private readonly ?OutboundMailer $mail = null,
    ) {
    }

    public function user(): ?array
    {
        if ($this->userResolved) {
            if ($this->resolvedUser !== null && $this->isStaffUser($this->resolvedUser)) {
                // Staff fingerprint / idle must still be enforced if session row changes.
                if (!$this->assertStaffSessionValid($this->resolvedUser, touch: false)) {
                    return null;
                }
            }

            return $this->resolvedUser;
        }

        // Resolve first — may call logout() which clears the memo.
        $user = $this->resolveUser();
        $this->userResolved = true;
        $this->resolvedUser = $user;

        return $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveUser(): ?array
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

        if ($this->isStaffUser($user) && !$this->assertStaffSessionValid($user, touch: true)) {
            return null;
        }

        // Members: touch at most once per request.
        if ($sessionId !== '' && !$this->isStaffUser($user)) {
            $this->userSessions->touch($sessionId);
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertStaffSessionValid(array $user, bool $touch): bool
    {
        $sessionId = $this->session->id();
        if ($sessionId === '') {
            return true;
        }

        if ($this->userSessions->isRevoked($sessionId)) {
            $this->logout();

            return false;
        }

        $active = $this->userSessions->findActive($sessionId);
        if ($active === null) {
            $this->logout();

            return false;
        }

        if ($this->staffFingerprintEnabled()) {
            $expected = (string) ($active['fingerprint'] ?? '');
            $current = $this->sessionFingerprint();
            if ($expected !== '' && !hash_equals($expected, $current)) {
                $this->securityLog?->log('session_fingerprint_mismatch', [
                    'ip' => $this->request->ip(),
                    'user_id' => (int) $user['id'],
                    'username' => (string) ($user['username'] ?? ''),
                    'role' => (string) ($user['role'] ?? ''),
                ]);
                $this->logout();

                return false;
            }
        }

        $idleMinutes = $this->staffIdleTimeoutMinutes();
        if ($idleMinutes > 0) {
            $lastSeen = strtotime((string) ($active['last_seen_at'] ?? ''));
            if ($lastSeen !== false && (time() - $lastSeen) > ($idleMinutes * 60)) {
                $this->securityLog?->log('session_idle_timeout', [
                    'ip' => $this->request->ip(),
                    'user_id' => (int) $user['id'],
                    'username' => (string) ($user['username'] ?? ''),
                    'role' => (string) ($user['role'] ?? ''),
                    'idle_minutes' => $idleMinutes,
                ]);
                $this->logout();

                return false;
            }
        }

        if ($touch) {
            $this->userSessions->touch($sessionId);
        }

        return true;
    }

    private function clearResolvedUser(): void
    {
        $this->userResolved = false;
        $this->resolvedUser = null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function login(array $user): void
    {
        $this->clearResolvedUser();

        $fingerprint = $this->sessionFingerprint();
        $isNewDevice = $this->isStaffUser($user)
            && !$this->userSessions->hasFingerprint((int) $user['id'], $fingerprint);

        $this->session->regenerate();
        $this->csrf->rotate();
        $this->session->set('user_id', (int) $user['id']);
        $this->session->set('password_changed_at', $user['password_changed_at'] ?? null);
        $this->session->forget(self::SESSION_STEPUP_AT);

        $sessionId = $this->session->id();
        if ($sessionId !== '') {
            $this->userSessions->register(
                $sessionId,
                (int) $user['id'],
                $fingerprint,
                $this->request->ip(),
                $this->request->userAgent(),
            );
        }

        $this->users->touchLogin((int) $user['id']);

        if ($isNewDevice) {
            $this->notifyStaffNewLogin($user);
        }
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
        $this->session->forget(self::SESSION_STEPUP_AT);
        $this->session->forget(self::SESSION_STEPUP_RETURN);
        $this->clearTotpPending();
        $this->clearResolvedUser();
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
        $this->clearResolvedUser();
        $this->session->regenerate();
        $this->session->forget('user_id');
        $this->session->forget('password_changed_at');
        $this->session->forget(self::SESSION_STEPUP_AT);
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

        $this->requireMandatoryTwoFactorEnrolled();
    }

    public function requireMod(): void
    {
        $this->requireLogin();
        if (!$this->isMod()) {
            Response::forbidden('Moderator access required.');
        }

        $this->requireMandatoryTwoFactorEnrolled();
    }

    /**
     * Sensitive staff actions: require recent TOTP (or password if no 2FA enrolled).
     */
    public function requireStaffStepUp(): void
    {
        $this->requireMod();

        if ($this->hasRecentStaffStepUp()) {
            return;
        }

        $return = $this->request->path();
        $query = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY) ?? '');
        if ($query !== '') {
            $return .= '?' . $query;
        }
        if ($this->request->isPost()) {
            $this->session->flash(
                'error',
                'Confirm your identity to continue, then try the action again.',
            );
            $referer = $this->request->header('Referer');
            if (is_string($referer) && $referer !== '') {
                $path = parse_url($referer, PHP_URL_PATH);
                if (is_string($path) && str_starts_with($path, '/')) {
                    $return = $path;
                    $q = parse_url($referer, PHP_URL_QUERY);
                    if (is_string($q) && $q !== '') {
                        $return .= '?' . $q;
                    }
                }
            } else {
                $return = '/admin';
            }
        }

        $this->session->set(self::SESSION_STEPUP_RETURN, $return);
        Response::redirect('/admin/step-up');
    }

    public function hasRecentStaffStepUp(): bool
    {
        $at = $this->session->get(self::SESSION_STEPUP_AT);
        if (!is_int($at) && !is_numeric($at)) {
            return false;
        }

        $ttl = $this->staffStepUpTtlMinutes() * 60;

        return (time() - (int) $at) < $ttl;
    }

    public function markStaffStepUp(): void
    {
        $this->session->set(self::SESSION_STEPUP_AT, time());
    }

    public function consumeStaffStepUpReturn(): string
    {
        $return = $this->session->get(self::SESSION_STEPUP_RETURN);
        $this->session->forget(self::SESSION_STEPUP_RETURN);
        if (!is_string($return) || $return === '' || !str_starts_with($return, '/')) {
            return '/admin';
        }

        return $return;
    }

    public function peekStaffStepUpReturn(): string
    {
        $return = $this->session->get(self::SESSION_STEPUP_RETURN);
        if (!is_string($return) || $return === '' || !str_starts_with($return, '/')) {
            return '/admin';
        }

        return $return;
    }

    private function requireMandatoryTwoFactorEnrolled(): void
    {
        $user = $this->user();
        if ($user === null || $this->twoFactor === null) {
            return;
        }

        if ($this->twoFactor->isMandatory($user) && !$this->twoFactor->isEnabled($user)) {
            $this->session->flash('error', 'Two-factor authentication is required for your role.');
            Response::redirect('/profile/2fa');
        }
    }

    public function sessionFingerprint(): string
    {
        return hash('sha256', $this->request->userAgent() . '|' . $this->request->ip());
    }

    /**
     * @param array<string, mixed> $user
     */
    public function isStaffUser(array $user): bool
    {
        return in_array((string) ($user['role'] ?? ''), [self::ROLE_ADMIN, self::ROLE_MOD], true);
    }

    private function staffFingerprintEnabled(): bool
    {
        if ($this->config === null) {
            return true;
        }

        return $this->config->get('security.staff_session_fingerprint', true) !== false;
    }

    private function staffIdleTimeoutMinutes(): int
    {
        if ($this->config === null) {
            return 30;
        }

        return max(0, (int) $this->config->get('security.staff_idle_timeout_minutes', 30));
    }

    private function staffStepUpTtlMinutes(): int
    {
        if ($this->config === null) {
            return 15;
        }

        return max(1, (int) $this->config->get('security.staff_stepup_ttl_minutes', 15));
    }

    private function staffLoginAlertsEnabled(): bool
    {
        if ($this->config === null) {
            return true;
        }

        return $this->config->get('security.staff_login_alerts', true) !== false;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function notifyStaffNewLogin(array $user): void
    {
        $this->securityLog?->log('staff_session_new', [
            'ip' => $this->request->ip(),
            'user_id' => (int) $user['id'],
            'username' => (string) ($user['username'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'user_agent' => mb_substr($this->request->userAgent(), 0, 200),
        ]);

        if (!$this->staffLoginAlertsEnabled() || $this->mail === null) {
            return;
        }

        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '' || !$this->mail->isEnabled()) {
            return;
        }

        $site = (string) ($this->config?->get('site.name', 'Latch') ?? 'Latch');
        $ip = $this->request->ip();
        $ua = mb_substr($this->request->userAgent(), 0, 200);
        $when = gmdate('Y-m-d H:i:s') . ' UTC';

        $body = "A new sign-in was detected for your {$site} staff account ({$user['username']}).\n\n"
            . "Time: {$when}\n"
            . "IP: {$ip}\n"
            . "Browser: {$ua}\n\n"
            . "If this was you, no action is needed.\n"
            . "If not, sign in, revoke other sessions under Profile, and change your password.\n";

        $this->mail->send($email, "[{$site}] New staff sign-in", $body);
    }
}

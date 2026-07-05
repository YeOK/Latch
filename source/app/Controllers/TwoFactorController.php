<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\QrCode;
use Latch\Core\Response;

final class TwoFactorController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function cancelPendingLogin(array $params = []): void
    {
        if ($this->app->auth()->check()) {
            Response::redirect('/');
        }

        if ($this->app->auth()->hasTotpPending()) {
            $this->app->auth()->clearTotpPending();
        }

        Response::redirect('/login');
    }

    public function showChallenge(array $params = []): void
    {
        if ($this->app->auth()->check()) {
            Response::redirect('/');
        }

        $user = $this->app->auth()->pendingTotpUser();
        if ($user === null || $this->app->auth()->isTotpSetupRequired()) {
            Response::redirect('/login');
        }

        $this->app->render('auth/totp_challenge.html.twig', [
            'username' => $user['username'],
        ]);
    }

    public function verifyChallenge(array $params = []): void
    {
        if (!$this->app->csrf()->validateAndRotate($this->app->request()->input('_csrf'))) {
            $this->app->session()->flash('error', 'Invalid form token.');
            Response::redirect('/login/2fa');
        }

        $user = $this->app->auth()->pendingTotpUser();
        if ($user === null || $this->app->auth()->isTotpSetupRequired()) {
            Response::redirect('/login');
        }

        $ip = $this->app->request()->ip();
        $code = (string) $this->app->request()->input('code', '');
        $recovery = (string) $this->app->request()->input('recovery_code', '');
        $twoFactor = $this->app->twoFactor();

        $valid = false;
        if (trim($recovery) !== '') {
            $valid = $twoFactor->verifyRecoveryCode((int) $user['id'], $recovery);
        } elseif (trim($code) !== '') {
            $valid = $twoFactor->verifyCode($user, $code);
        }

        if (!$valid) {
            $this->app->rateLimiter()->recordLoginAttempt($ip, (string) $user['username'], false);
            $this->app->securityLog()->log('login_totp_fail', [
                'ip' => $ip,
                'user_id' => (int) $user['id'],
            ]);
            $this->app->session()->flash('error', 'Invalid authentication code.');
            $this->app->render('auth/totp_challenge.html.twig', [
                'username' => $user['username'],
            ]);

            return;
        }

        $fresh = $this->app->users()->findById((int) $user['id']);
        if ($fresh === null) {
            $this->app->auth()->clearTotpPending();
            Response::redirect('/login');
        }

        $this->app->auth()->completeLoginAfterTotp($fresh);
        $this->app->securityLog()->log('login_success', [
            'ip' => $ip,
            'user_id' => (int) $fresh['id'],
            'username' => $fresh['username'],
            'totp' => true,
        ]);
        Response::redirect('/');
    }

    public function showLoginSetup(array $params = []): void
    {
        if ($this->app->auth()->check()) {
            Response::redirect('/');
        }

        $user = $this->app->auth()->pendingTotpUser();
        if ($user === null || !$this->app->auth()->isTotpSetupRequired()) {
            Response::redirect('/login');
        }

        $this->renderSetupForm($user, '/login/2fa/setup');
    }

    public function confirmLoginSetup(array $params = []): void
    {
        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            $this->app->session()->flash('error', 'Invalid form token.');
            Response::redirect('/login/2fa/setup');
        }

        $user = $this->app->auth()->pendingTotpUser();
        if ($user === null || !$this->app->auth()->isTotpSetupRequired()) {
            Response::redirect('/login');
        }

        if (!$this->requireEncryptionKeyForTotp()) {
            Response::redirect('/login');
        }

        $this->confirmSetup($user, true);
    }

    public function showProfile(array $params = []): void
    {
        $this->app->auth()->requireLogin();
        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $twoFactor = $this->app->twoFactor();
        $this->app->render('profile/two_factor.html.twig', [
            'totp_enabled' => $twoFactor->isEnabled($user),
            'totp_encryption_ready' => $twoFactor->encryptionReady(),
            'totp_mandatory' => $twoFactor->isMandatory($user),
            'recovery_codes_remaining' => $twoFactor->isEnabled($user)
                ? $twoFactor->unusedRecoveryCodeCount((int) $user['id'])
                : 0,
            'recovery_codes' => $this->app->session()->flash('recovery_codes'),
        ]);
    }

    public function beginEnable(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        if ($this->app->twoFactor()->isEnabled($user)) {
            $this->app->session()->flash('error', 'Two-factor authentication is already enabled.');
            Response::redirect('/profile/2fa');
        }

        if (!$this->requireEncryptionKeyForTotp()) {
            Response::redirect('/profile/2fa');
        }

        $secret = $this->app->twoFactor()->generateSecret();
        $this->app->session()->set('totp_setup_secret', $secret);
        Response::redirect('/profile/2fa/enable');
    }

    public function showEnable(array $params = []): void
    {
        $this->app->auth()->requireLogin();
        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        if ($this->app->twoFactor()->isEnabled($user)) {
            Response::redirect('/profile/2fa');
        }

        $secret = (string) $this->app->session()->get('totp_setup_secret', '');
        if ($secret === '') {
            $this->app->session()->flash('error', 'Start setup again to generate a new secret.');
            Response::redirect('/profile/2fa');
        }

        $this->renderSetupForm($user, '/profile/2fa/confirm');
    }

    public function confirmEnable(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validateAndRotate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        if (!$this->requireEncryptionKeyForTotp()) {
            Response::redirect('/profile/2fa');
        }

        $this->confirmSetup($user, false);
    }

    public function disable(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validateAndRotate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $twoFactor = $this->app->twoFactor();
        if ($twoFactor->isMandatory($user)) {
            $this->app->session()->flash('error', 'Two-factor authentication is required for your role.');
            Response::redirect('/profile/2fa');
        }

        if (!$twoFactor->isEnabled($user)) {
            Response::redirect('/profile/2fa');
        }

        $password = (string) $this->app->request()->input('password', '');
        $code = (string) $this->app->request()->input('code', '');
        if (!password_verify($password, (string) $user['password_hash']) || !$twoFactor->verifyCode($user, $code)) {
            $this->app->session()->flash('error', 'Password or authentication code is incorrect.');
            Response::redirect('/profile/2fa');
        }

        $twoFactor->disable((int) $user['id']);
        $this->app->securityLog()->log('totp_disabled', [
            'ip' => $this->app->request()->ip(),
            'user_id' => (int) $user['id'],
        ]);
        $this->app->session()->flash('success', 'Two-factor authentication disabled.');
        Response::redirect('/profile/2fa');
    }

    public function regenerateRecovery(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validateAndRotate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $twoFactor = $this->app->twoFactor();
        if (!$twoFactor->isEnabled($user)) {
            Response::redirect('/profile/2fa');
        }

        $password = (string) $this->app->request()->input('password', '');
        $code = (string) $this->app->request()->input('code', '');
        if (!password_verify($password, (string) $user['password_hash']) || !$twoFactor->verifyCode($user, $code)) {
            $this->app->session()->flash('error', 'Password or authentication code is incorrect.');
            Response::redirect('/profile/2fa');
        }

        $codes = $twoFactor->issueRecoveryCodes((int) $user['id']);
        $this->app->session()->flash('recovery_codes', implode("\n", $codes));
        $this->app->securityLog()->log('totp_recovery_regenerated', [
            'ip' => $this->app->request()->ip(),
            'user_id' => (int) $user['id'],
        ]);
        Response::redirect('/profile/2fa');
    }

    private function confirmSetup(array $user, bool $fromLogin): void
    {
        $secret = (string) $this->app->session()->get('totp_setup_secret', '');
        $code = (string) $this->app->request()->input('code', '');
        if ($secret === '') {
            $this->app->session()->flash('error', 'Setup expired. Please start again.');
            Response::redirect($fromLogin ? '/login/2fa/setup' : '/profile/2fa');
        }

        $twoFactor = $this->app->twoFactor();
        try {
            $enabled = $twoFactor->enable((int) $user['id'], $secret, $code);
        } catch (\RuntimeException $e) {
            $this->app->session()->flash('error', $e->getMessage());
            $this->renderSetupForm($user, $fromLogin ? '/login/2fa/setup' : '/profile/2fa/confirm');

            return;
        }

        if (!$enabled) {
            $this->app->session()->flash('error', 'Invalid authentication code. Check your app and try again.');
            $this->renderSetupForm($user, $fromLogin ? '/login/2fa/setup' : '/profile/2fa/confirm');

            return;
        }

        $codes = $twoFactor->issueRecoveryCodes((int) $user['id']);
        $this->app->session()->forget('totp_setup_secret');
        $this->app->session()->flash('recovery_codes', implode("\n", $codes));
        $this->app->securityLog()->log('totp_enabled', [
            'ip' => $this->app->request()->ip(),
            'user_id' => (int) $user['id'],
        ]);

        if ($fromLogin) {
            $fresh = $this->app->users()->findById((int) $user['id']);
            if ($fresh !== null) {
                $this->app->auth()->completeLoginAfterTotp($fresh);
                $this->app->session()->flash('success', 'Two-factor authentication enabled. Save your recovery codes below.');
                Response::redirect('/profile/2fa');
            }
        }

        $this->app->session()->flash('success', 'Two-factor authentication enabled. Save your recovery codes below.');
        Response::redirect('/profile/2fa');
    }

    private function requireEncryptionKeyForTotp(): bool
    {
        if ($this->app->twoFactor()->encryptionReady()) {
            return true;
        }

        $this->app->session()->flash(
            'error',
            'Set security.encryption_key in config/local.php before enabling two-factor authentication.',
        );

        return false;
    }

    private function renderSetupForm(array $user, string $action): void
    {
        if (!$this->requireEncryptionKeyForTotp()) {
            Response::redirect($this->app->auth()->isTotpSetupRequired() ? '/login' : '/profile/2fa');
        }

        $secret = (string) $this->app->session()->get('totp_setup_secret', '');
        if ($secret === '') {
            $secret = $this->app->twoFactor()->generateSecret();
            $this->app->session()->set('totp_setup_secret', $secret);
        }

        $provisioningUri = $this->app->twoFactor()->provisioningUri($user, $secret);

        $this->app->render('auth/totp_setup.html.twig', [
            'username' => $user['username'],
            'secret' => $secret,
            'secret_display' => trim(chunk_split($secret, 4, ' ')),
            'provisioning_uri' => $provisioningUri,
            'qr_svg' => (new QrCode())->svg($provisioningUri),
            'form_action' => $action,
            'setup_required' => $this->app->auth()->isTotpSetupRequired(),
        ]);
    }
}
<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\Oidc\OidcConfig;
use Latch\Core\Response;
use RuntimeException;

final class OidcController
{
    private const STATE_SESSION_KEY = 'oidc_state';
    private const PROVIDER_SESSION_KEY = 'oidc_provider';

    public function __construct(private readonly Application $app)
    {
    }

    public function start(array $params = []): void
    {
        if ($this->app->auth()->check()) {
            Response::redirect('/');
        }

        $provider = OidcConfig::normalizeProvider((string) ($params['provider'] ?? ''));
        if ($provider === null || !$this->app->oidcConfig()->isEnabled($provider)) {
            $this->app->session()->flash('error', 'That sign-in provider is not available.');
            Response::redirect('/login');
        }

        $state = bin2hex(random_bytes(16));
        $this->app->session()->set(self::STATE_SESSION_KEY, $state);
        $this->app->session()->set(self::PROVIDER_SESSION_KEY, $provider);

        try {
            $url = $this->app->oidc()->buildAuthorizationUrl($provider, $state);
        } catch (RuntimeException) {
            $this->clearOidcSession();
            $this->app->session()->flash('error', 'Social sign-in is misconfigured.');
            Response::redirect('/login');
        }

        Response::redirect($url);
    }

    public function callback(array $params = []): void
    {
        if ($this->app->auth()->check()) {
            Response::redirect('/');
        }

        $provider = OidcConfig::normalizeProvider((string) ($params['provider'] ?? ''));
        if ($provider === null || !$this->app->oidcConfig()->isEnabled($provider)) {
            $this->fail('That sign-in provider is not available.');
        }

        $expectedProvider = $this->app->session()->get(self::PROVIDER_SESSION_KEY);
        if (!is_string($expectedProvider) || $expectedProvider !== $provider) {
            $this->fail('Sign-in session expired. Please try again.');
        }

        $state = (string) $this->app->request()->input('state', '');
        $expectedState = $this->app->session()->get(self::STATE_SESSION_KEY);
        if (!is_string($expectedState) || $expectedState === '' || !hash_equals($expectedState, $state)) {
            $this->fail('Invalid sign-in state. Please try again.');
        }

        $error = trim((string) $this->app->request()->input('error', ''));
        if ($error !== '') {
            $this->fail('Sign-in was cancelled or denied.');
        }

        $code = trim((string) $this->app->request()->input('code', ''));
        if ($code === '') {
            $this->fail('Missing authorization code.');
        }

        $this->clearOidcSession();

        try {
            $profile = $this->app->oidc()->exchangeCode($provider, $code);
            $resolved = $this->app->oidc()->resolveUser($provider, $profile);
        } catch (RuntimeException $exception) {
            $this->app->securityLog()->log('oidc_fail', [
                'ip' => $this->app->request()->ip(),
                'provider' => $provider,
                'message' => $exception->getMessage(),
            ]);
            $this->fail($exception->getMessage());
        }

        $user = $resolved['user'];
        $ip = $this->app->request()->ip();

        if ($this->app->users()->isDeleted($user)) {
            $this->app->securityLog()->log('login_deleted', [
                'ip' => $ip,
                'user_id' => (int) $user['id'],
                'username' => $user['username'],
                'provider' => $provider,
            ]);
            $this->fail($this->app->users()->deletedLoginMessage());
        }

        if ($this->app->users()->isBanned($user)) {
            $this->app->securityLog()->log('login_banned', [
                'ip' => $ip,
                'user_id' => (int) $user['id'],
                'username' => $user['username'],
                'provider' => $provider,
            ]);
            $this->fail($this->app->users()->banLoginMessage($user));
        }

        if ($this->app->users()->isLocked($user)) {
            $this->fail('Your account is temporarily locked. Try again later.');
        }

        $twoFactor = $this->app->twoFactor();
        if ($twoFactor->mustEnroll($user)) {
            $this->app->auth()->beginTotpPending($user, true);
            $this->app->securityLog()->log('login_totp_setup_required', [
                'ip' => $ip,
                'user_id' => (int) $user['id'],
                'provider' => $provider,
            ]);
            Response::redirect('/login/2fa/setup');
        }

        if ($twoFactor->needsChallenge($user)) {
            $this->app->auth()->beginTotpPending($user, false);
            $this->app->securityLog()->log('login_totp_challenge', [
                'ip' => $ip,
                'user_id' => (int) $user['id'],
                'provider' => $provider,
            ]);
            Response::redirect('/login/2fa');
        }

        $this->app->auth()->login($user);
        $this->app->securityLog()->log('login_success', [
            'ip' => $ip,
            'user_id' => (int) $user['id'],
            'username' => $user['username'],
            'provider' => $provider,
            'created' => $resolved['created'],
        ]);

        if ($resolved['created']) {
            $this->app->fireUserRegister($user);
            $this->app->session()->flash('success', 'Welcome to the forum!');
        }

        Response::redirect('/');
    }

    private function fail(string $message): void
    {
        $this->clearOidcSession();
        $this->app->session()->flash('error', $message);
        Response::redirect('/login');
    }

    private function clearOidcSession(): void
    {
        $this->app->session()->forget(self::STATE_SESSION_KEY);
        $this->app->session()->forget(self::PROVIDER_SESSION_KEY);
    }
}
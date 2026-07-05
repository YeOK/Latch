<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Oidc;

use JsonException;
use Latch\Core\Config;
use Latch\Core\InputValidator;
use Latch\Core\RegistrationGuard;
use Latch\Core\ThemeMode;
use Latch\Models\OidcIdentityRepository;
use Latch\Models\SettingRepository;
use Latch\Models\UserRepository;
use RuntimeException;

final class OidcService
{
    public function __construct(
        private readonly OidcConfig $config,
        private readonly OidcHttpClient $http,
        private readonly OidcIdentityRepository $identities,
        private readonly UserRepository $users,
        private readonly SettingRepository $settings,
        private readonly InputValidator $inputValidator,
        private readonly Config $appConfig,
        private readonly RegistrationGuard $registrationGuard,
    ) {
    }

    public function buildAuthorizationUrl(string $provider, string $state): string
    {
        return match ($provider) {
            OidcConfig::PROVIDER_GOOGLE => $this->googleAuthorizationUrl($state),
            OidcConfig::PROVIDER_GITHUB => $this->githubAuthorizationUrl($state),
            default => throw new RuntimeException('Unsupported OIDC provider.'),
        };
    }

    public function exchangeCode(string $provider, string $code): OidcProviderProfile
    {
        return match ($provider) {
            OidcConfig::PROVIDER_GOOGLE => $this->exchangeGoogleCode($code),
            OidcConfig::PROVIDER_GITHUB => $this->exchangeGithubCode($code),
            default => throw new RuntimeException('Unsupported OIDC provider.'),
        };
    }

    /**
     * @return array{user: array, created: bool}
     */
    public function resolveUser(string $provider, OidcProviderProfile $profile): array
    {
        $identity = $this->identities->findByProviderSubject($provider, $profile->subject);
        if ($identity !== null) {
            $user = $this->users->findById((int) $identity['user_id']);
            if ($user === null) {
                throw new RuntimeException('Linked account no longer exists.');
            }

            return ['user' => $user, 'created' => false];
        }

        $email = $profile->email !== null ? strtolower(trim($profile->email)) : '';
        if ($email !== '' && $profile->emailVerified) {
            $existing = $this->users->findByEmail($email);
            if ($existing !== null) {
                $this->identities->link((int) $existing['id'], $provider, $profile->subject, $email);
                if (!$this->users->isEmailVerified($existing)) {
                    $this->users->markEmailVerified((int) $existing['id']);
                    $existing = $this->users->findById((int) $existing['id']) ?? $existing;
                }

                return ['user' => $existing, 'created' => false];
            }
        }

        if ($email === '' || !$profile->emailVerified) {
            throw new RuntimeException('The provider did not return a verified email address.');
        }

        $this->assertNewRegistrationAllowed();

        $username = $this->suggestUsername($profile);
        $user = $this->users->createSocial(
            $username,
            $email,
            'member',
            $this->defaultThemeMode(),
        );
        $this->users->markEmailVerified((int) $user['id']);
        $this->identities->link((int) $user['id'], $provider, $profile->subject, $email);
        $user = $this->users->findById((int) $user['id']) ?? $user;
        $this->registrationGuard->recordAttempt(true);

        return ['user' => $user, 'created' => true];
    }

    private function assertNewRegistrationAllowed(): void
    {
        if (!$this->allowRegistration()) {
            $this->registrationGuard->logBlocked('registration_disabled');
            throw new RuntimeException('Registration is disabled.');
        }

        $maxPerHour = (int) $this->appConfig->get('security.registration_max_per_ip_hour', 3);
        if ($this->registrationGuard->tooManyAttempts($maxPerHour, 60)) {
            $this->registrationGuard->logBlocked('rate_limit');
            $this->registrationGuard->recordAttempt(false);
            throw new RuntimeException('Too many registration attempts from your network. Try again later.');
        }
    }

    private function allowRegistration(): bool
    {
        if ($this->settings->get('allow_registration') !== null) {
            return $this->settings->getBool('allow_registration');
        }

        return (bool) $this->appConfig->get('forum.allow_registration', true);
    }

    public function suggestUsername(OidcProviderProfile $profile): string
    {
        $candidates = [];

        if ($profile->preferredUsername !== null && $profile->preferredUsername !== '') {
            $candidates[] = $profile->preferredUsername;
        }

        if ($profile->email !== null && $profile->email !== '') {
            $local = strstr($profile->email, '@', true);
            if (is_string($local) && $local !== '') {
                $candidates[] = $local;
            }
        }

        if ($profile->displayName !== null && $profile->displayName !== '') {
            $candidates[] = $profile->displayName;
        }

        $candidates[] = 'member';

        foreach ($candidates as $candidate) {
            $base = $this->sanitizeUsername($candidate);
            if ($base === '') {
                continue;
            }

            $username = $this->ensureUniqueUsername($base);
            if ($username !== '') {
                return $username;
            }
        }

        return $this->ensureUniqueUsername('member');
    }

    private function googleAuthorizationUrl(string $state): string
    {
        $params = [
            'client_id' => $this->config->clientId(OidcConfig::PROVIDER_GOOGLE),
            'redirect_uri' => $this->config->redirectUri(OidcConfig::PROVIDER_GOOGLE),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    private function githubAuthorizationUrl(string $state): string
    {
        $params = [
            'client_id' => $this->config->clientId(OidcConfig::PROVIDER_GITHUB),
            'redirect_uri' => $this->config->redirectUri(OidcConfig::PROVIDER_GITHUB),
            'scope' => 'read:user user:email',
            'state' => $state,
        ];

        return 'https://github.com/login/oauth/authorize?' . http_build_query($params);
    }

    private function exchangeGoogleCode(string $code): OidcProviderProfile
    {
        $response = $this->http->postForm('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $this->config->clientId(OidcConfig::PROVIDER_GOOGLE),
            'client_secret' => $this->config->clientSecret(OidcConfig::PROVIDER_GOOGLE),
            'redirect_uri' => $this->config->redirectUri(OidcConfig::PROVIDER_GOOGLE),
            'grant_type' => 'authorization_code',
        ]);

        if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Google token exchange failed.');
        }

        try {
            $token = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Google token response was invalid.');
        }

        $accessToken = (string) ($token['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Google token response missing access_token.');
        }

        $userinfo = $this->http->get(
            'https://openidconnect.googleapis.com/v1/userinfo',
            ['Authorization: Bearer ' . $accessToken],
        );

        if ($userinfo === null || $userinfo['status'] < 200 || $userinfo['status'] >= 300) {
            throw new RuntimeException('Google userinfo request failed.');
        }

        try {
            $data = json_decode($userinfo['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Google userinfo response was invalid.');
        }

        $subject = (string) ($data['sub'] ?? '');
        if ($subject === '') {
            throw new RuntimeException('Google profile missing subject.');
        }

        return new OidcProviderProfile(
            $subject,
            isset($data['email']) ? (string) $data['email'] : null,
            ($data['email_verified'] ?? false) === true,
            null,
            isset($data['name']) ? (string) $data['name'] : null,
        );
    }

    private function exchangeGithubCode(string $code): OidcProviderProfile
    {
        $response = $this->http->postForm(
            'https://github.com/login/oauth/access_token',
            [
                'client_id' => $this->config->clientId(OidcConfig::PROVIDER_GITHUB),
                'client_secret' => $this->config->clientSecret(OidcConfig::PROVIDER_GITHUB),
                'code' => $code,
                'redirect_uri' => $this->config->redirectUri(OidcConfig::PROVIDER_GITHUB),
            ],
            ['Accept: application/json'],
        );

        if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('GitHub token exchange failed.');
        }

        try {
            $token = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('GitHub token response was invalid.');
        }

        $accessToken = (string) ($token['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('GitHub token response missing access_token.');
        }

        $authHeader = ['Authorization: Bearer ' . $accessToken, 'Accept: application/json', 'User-Agent: Latch-OIDC'];

        $userResponse = $this->http->get('https://api.github.com/user', $authHeader);
        if ($userResponse === null || $userResponse['status'] < 200 || $userResponse['status'] >= 300) {
            throw new RuntimeException('GitHub user request failed.');
        }

        try {
            $user = json_decode($userResponse['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('GitHub user response was invalid.');
        }

        $subject = (string) ($user['id'] ?? '');
        if ($subject === '') {
            throw new RuntimeException('GitHub profile missing id.');
        }

        $email = isset($user['email']) && is_string($user['email']) && $user['email'] !== ''
            ? $user['email']
            : $this->fetchGithubPrimaryEmail($accessToken);

        return new OidcProviderProfile(
            $subject,
            $email,
            $email !== null,
            isset($user['login']) ? (string) $user['login'] : null,
            isset($user['name']) ? (string) $user['name'] : null,
        );
    }

    private function fetchGithubPrimaryEmail(string $accessToken): ?string
    {
        $response = $this->http->get(
            'https://api.github.com/user/emails',
            ['Authorization: Bearer ' . $accessToken, 'Accept: application/json', 'User-Agent: Latch-OIDC'],
        );

        if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }

        try {
            $emails = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($emails)) {
            return null;
        }

        foreach ($emails as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['primary'] ?? false) === true && ($row['verified'] ?? false) === true) {
                return isset($row['email']) ? (string) $row['email'] : null;
            }
        }

        foreach ($emails as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['verified'] ?? false) === true) {
                return isset($row['email']) ? (string) $row['email'] : null;
            }
        }

        return null;
    }

    private function sanitizeUsername(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? '';
        $value = trim($value, '_-');

        if ($value === '' || !preg_match('/^[a-z0-9]/', $value)) {
            $value = 'u' . $value;
        }

        $max = 32;
        if (mb_strlen($value) > $max) {
            $value = mb_substr($value, 0, $max);
        }

        if ($this->inputValidator->usernameError($value) === null) {
            return $value;
        }

        $value = preg_replace('/[^a-z0-9]/', '', $value) ?? '';
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) > $max) {
            $value = mb_substr($value, 0, $max);
        }

        return $this->inputValidator->usernameError($value) === null ? $value : '';
    }

    private function ensureUniqueUsername(string $base): string
    {
        if ($this->users->findByUsername($base) === null) {
            return $base;
        }

        for ($i = 0; $i < 100; $i++) {
            $suffix = (string) random_int(10, 9999);
            $maxBase = 32 - strlen($suffix);
            $candidate = mb_substr($base, 0, max(1, $maxBase)) . $suffix;
            if ($this->inputValidator->usernameError($candidate) === null
                && $this->users->findByUsername($candidate) === null) {
                return $candidate;
            }
        }

        return '';
    }

    private function defaultThemeMode(): string
    {
        return ThemeMode::normalizePreference(
            (string) $this->settings->get('default_theme_mode', ThemeMode::SYSTEM),
        );
    }
}
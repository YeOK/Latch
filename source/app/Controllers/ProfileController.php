<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\Cache;
use Latch\Core\OAuthScopes;
use Latch\Core\Response;
use Latch\Core\Locale;
use Latch\Core\Plugins\ProfileSaveContext;
use Latch\Core\ThemeMode;
final class ProfileController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function show(array $params = []): void
    {
        $this->app->auth()->requireLogin();
        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $authorizedApps = [];
        foreach ($this->app->oauthTokens()->listAuthorizedAppsForUser((int) $user['id']) as $app) {
            $scopeLabels = [];
            foreach ($app['scopes'] as $scope) {
                $scopeLabels[$scope] = OAuthScopes::label($scope);
            }
            $authorizedApps[] = [
                'client_id' => $app['client_id'],
                'client_name' => $app['client_name'],
                'authorized_at' => $app['authorized_at'],
                'scopes' => $app['scopes'],
                'scope_labels' => $scopeLabels,
            ];
        }

        $this->app->render('profile/index.html.twig', [
            'sessions' => $this->app->userSessions()->listForUser((int) $user['id']),
            'authorized_apps' => $authorizedApps,
            'current_session_id' => $this->app->session()->id(),
            'theme_mode' => ThemeMode::normalizePreference((string) ($user['theme_mode'] ?? ThemeMode::SYSTEM)),
            'user_locale' => Locale::normalize((string) ($user['locale'] ?? $this->app->defaultLocale())),
            'avatar_src' => $this->app->resolveAvatar((string) $user['email']),
            'avatar_pending_src' => $this->app->resolveAvatarPending((string) $user['email'], 64),
            'bio' => (string) ($user['bio'] ?? ''),
            'notify_email' => $this->app->users()->wantsEmailNotifications($user),
            'accept_messages' => $this->app->users()->acceptsMessages($user),
            'mail_enabled' => $this->app->settings()->getBool('mail_enabled') && $this->app->mail()->isConfigured(),
            'anonymise_posts_on_delete' => $this->app->anonymisePostsOnDelete(),
            'plugin_profile_form_html' => $this->app->collectProfileForm($user),
        ]);
    }

    public function requestEmailChange(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        if (!$this->app->settings()->getBool('mail_enabled') || !$this->app->mail()->isConfigured()) {
            $this->app->session()->flash('error', 'Email change is unavailable — outbound mail is not configured.');
            Response::redirect('/profile');
        }

        $ip = $this->app->request()->ip();
        if ($this->app->rateLimiter()->tooManyLoginAttempts($ip, 5, 15)) {
            $this->app->session()->flash('error', 'Too many requests. Try again later.');
            Response::redirect('/profile');
        }

        $password = (string) $this->app->request()->input('current_password', '');
        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->app->session()->flash('error', 'Current password is incorrect.');
            Response::redirect('/profile');
        }

        $newEmail = strtolower(trim((string) $this->app->request()->input('new_email', '')));
        $emailError = $this->app->inputValidator()->emailError($newEmail);
        if ($emailError !== null) {
            $this->app->session()->flash('error', $emailError);
            Response::redirect('/profile');
        }

        if (strcasecmp($newEmail, (string) $user['email']) === 0) {
            $this->app->session()->flash('error', 'That is already your email address.');
            Response::redirect('/profile');
        }

        if ($this->app->users()->findByEmail($newEmail) !== null) {
            $this->app->session()->flash('error', 'That email address is already in use.');
            Response::redirect('/profile');
        }

        $token = bin2hex(random_bytes(32));
        $this->app->emailChanges()->create((int) $user['id'], $newEmail, $token);
        $confirmUrl = $this->app->mail()->siteUrl() . '/confirm-email-change?token=' . rawurlencode($token);
        $sent = $this->app->mail()->send(
            $newEmail,
            'Confirm your new Latch email',
            'Hello ' . $user['username'] . ",\n\n"
            . "Confirm this email change for your Latch account:\n{$confirmUrl}\n\n"
            . "This link expires in 24 hours. If you did not request this, ignore this message.\n\n"
            . 'Current address on file: ' . $user['email'],
        );

        $this->app->rateLimiter()->recordLoginAttempt($ip, $newEmail, false);

        if (!$sent) {
            $this->app->securityLog()->log('mail_send_failed', [
                'context' => 'email_change',
                'user_id' => (int) $user['id'],
                'error' => $this->app->mail()->lastError(),
            ]);
            $this->app->session()->flash('error', 'Could not send confirmation email. Try again later.');
            Response::redirect('/profile');
        }

        $this->app->securityLog()->log('email_change_request', [
            'ip' => $ip,
            'user_id' => (int) $user['id'],
        ]);
        $this->app->session()->flash(
            'success',
            'Confirmation link sent to ' . $newEmail . '. Your address will not change until you confirm.',
        );
        Response::redirect('/profile');
    }

    public function saveProfile(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $bio = trim((string) $this->app->request()->input('bio', ''));
        $bioError = $this->app->inputValidator()->bioError($bio);
        if ($bioError !== null) {
            $this->app->session()->flash('error', $bioError);
            Response::redirect('/profile');
        }

        $avatarField = $this->app->request()->input('avatar_url');
        $avatarUrlInput = is_string($avatarField) ? trim($avatarField) : null;

        $profileContext = new ProfileSaveContext($bio, $user, $avatarUrlInput);
        $rejectReason = $this->app->applyProfileBeforeSave($profileContext);
        if ($rejectReason !== null) {
            $this->app->session()->flash('error', $rejectReason);
            Response::redirect('/profile');
        }

        $this->app->users()->updateProfile((int) $user['id'], $profileContext->bio);
        if ($profileContext->updateAvatarUrl) {
            $this->app->users()->updateAvatarUrl((int) $user['id'], $profileContext->avatarUrl);
        }
        $this->app->invalidateCacheTags([Cache::tagUser((int) $user['id'])]);
        $this->app->session()->flash('success', 'Profile updated.');
        Response::redirect('/profile');
    }

    public function saveTheme(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $mode = ThemeMode::normalizePreference((string) $this->app->request()->input('theme_mode', ThemeMode::SYSTEM));
        $this->app->users()->updateThemeMode((int) $user['id'], $mode);

        if ($this->app->request()->header('X-Requested-With') === 'XMLHttpRequest') {
            Response::json(['ok' => true, 'theme_mode' => $mode]);
        }

        $this->app->session()->flash('success', $this->app->trans('profile.theme_saved'));
        Response::redirect('/profile');
    }

    public function saveLocale(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $locale = Locale::normalize((string) $this->app->request()->input('locale', Locale::DEFAULT));
        $this->app->users()->updateLocale((int) $user['id'], $locale);

        setcookie(
            Locale::COOKIE,
            $locale,
            [
                'expires' => time() + 86400 * 365,
                'path' => '/',
                'secure' => $this->app->request()->isHttps(),
                'httponly' => false,
                'samesite' => 'Lax',
            ],
        );

        $this->app->session()->flash('success', $this->app->trans('profile.locale_saved'));
        Response::redirect('/profile');
    }

    public function saveNotifyEmail(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $enabled = $this->app->request()->input('notify_email') === '1';
        $this->app->users()->updateNotifyEmail((int) $user['id'], $enabled);
        $this->app->session()->flash('success', 'Email notification preference saved.');
        Response::redirect('/profile');
    }

    public function saveAcceptMessages(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        if (!$this->app->directMessages()->isAvailable()) {
            $this->app->session()->flash('error', 'Direct messages are not available yet.');
            Response::redirect('/profile');
        }

        $enabled = $this->app->request()->input('accept_messages') === '1';
        $this->app->users()->updateAcceptMessages((int) $user['id'], $enabled);
        $this->app->session()->flash('success', 'Message preference saved.');
        Response::redirect('/profile');
    }

    public function changePassword(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validateAndRotate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $current = (string) $this->app->request()->input('current_password', '');
        $new = (string) $this->app->request()->input('new_password', '');
        $confirm = (string) $this->app->request()->input('new_password_confirm', '');

        if (!password_verify($current, (string) $user['password_hash'])) {
            $this->app->session()->flash('error', 'Current password is incorrect.');
            Response::redirect('/profile');
        }

        $passwordError = $this->app->inputValidator()->passwordError($new);
        if ($passwordError !== null) {
            $this->app->session()->flash('error', $passwordError);
            Response::redirect('/profile');
        }

        if (!hash_equals($new, $confirm)) {
            $this->app->session()->flash('error', 'New passwords do not match.');
            Response::redirect('/profile');
        }

        $this->app->users()->updatePassword((int) $user['id'], $new);
        $this->app->emailChanges()->invalidateForUser((int) $user['id']);
        $this->app->userSessions()->revokeAllExcept((int) $user['id'], $this->app->session()->id());

        $updated = $this->app->users()->findById((int) $user['id']);
        if ($updated !== null) {
            $this->app->session()->set('password_changed_at', $updated['password_changed_at'] ?? null);
        }

        $this->app->securityLog()->log('password_change', [
            'ip' => $this->app->request()->ip(),
            'user_id' => (int) $user['id'],
        ]);

        $this->app->session()->flash('success', 'Password updated.');
        Response::redirect('/profile');
    }

    public function revokeSession(array $params): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $sessionId = (string) ($params['id'] ?? '');
        if ($sessionId === $this->app->session()->id()) {
            $this->app->session()->flash('error', 'Use sign out to end your current session.');
            Response::redirect('/profile');
        }

        $this->app->userSessions()->revoke($sessionId, (int) $user['id']);
        $this->app->session()->flash('success', 'Session revoked.');
        Response::redirect('/profile');
    }

    public function revokeAllSessions(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $this->app->userSessions()->revokeAllExcept((int) $user['id'], $this->app->session()->id());
        $this->app->session()->flash('success', 'All other sessions revoked.');
        Response::redirect('/profile');
    }

    public function revokeOAuthApp(array $params): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $userId = (int) $user['id'];
        $clientId = trim((string) ($params['client_id'] ?? ''));
        if ($clientId === '' || !preg_match('/^latch_[a-f0-9]{24}$/', $clientId)) {
            $this->app->session()->flash('error', 'Invalid application.');
            Response::redirect('/profile');
        }

        if (!$this->app->oauthTokens()->userHasActiveDelegation($userId, $clientId)) {
            $this->app->session()->flash('error', 'That application is not connected to your account.');
            Response::redirect('/profile');
        }

        $client = $this->app->oauthClients()->findActiveByClientId($clientId);
        $appName = $client !== null ? (string) $client['name'] : $clientId;

        $this->app->oauthTokens()->revokeUserDelegation($userId, $clientId);

        $this->app->securityLog()->log('oauth_app_revoke', [
            'ip' => $this->app->request()->ip(),
            'user_id' => $userId,
            'client_id' => $clientId,
            'client_name' => $appName,
        ]);

        $this->app->session()->flash('success', 'Access revoked for ' . $appName . '.');
        Response::redirect('/profile');
    }

    public function exportData(array $params = []): void
    {
        $this->app->auth()->requireLogin();
        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $userId = (int) $user['id'];
        $posts = $this->app->posts()->listByUser($userId);

        $export = [
            'exported_at' => gmdate('c'),
            'profile' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
                'created_at' => $user['created_at'],
            ],
            'posts' => $posts,
        ];

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="latch-export-' . $user['username'] . '.json"');
        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function deleteAccount(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        if ((int) $user['id'] === 1) {
            $this->app->session()->flash('error', 'The founder account cannot be deleted.');
            Response::redirect('/profile');
        }

        $password = (string) $this->app->request()->input('password', '');
        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->app->session()->flash('error', 'Password is incorrect.');
            Response::redirect('/profile');
        }

        $userId = (int) $user['id'];
        $topicIds = [];
        if ($this->app->anonymisePostsOnDelete()) {
            $topicIds = array_values(array_unique(array_merge(
                $this->app->posts()->anonymiseContentByUser($userId),
                $this->app->topics()->anonymiseTitlesByUser($userId),
            )));
            if ($this->app->search()->isEnabled()) {
                foreach ($topicIds as $topicId) {
                    $this->app->search()->indexTopic($topicId);
                }
            }
        }

        $this->app->users()->anonymise($userId);
        $this->app->userSessions()->revokeAllForUser($userId);
        $this->app->emailChanges()->invalidateForUser($userId);

        $tags = [Cache::tagUser($userId), Cache::tagSite()];
        foreach ($topicIds as $topicId) {
            $tags[] = Cache::tagTopic($topicId);
            $topic = $this->app->topics()->findById($topicId);
            if ($topic !== null) {
                $tags[] = Cache::tagBoard((int) $topic['board_id']);
            }
        }
        $this->app->invalidateCacheTags(array_values(array_unique($tags)));
        $this->app->securityLog()->log('account_delete', [
            'ip' => $this->app->request()->ip(),
            'user_id' => (int) $user['id'],
        ]);

        $this->app->auth()->logout();
        $this->app->session()->flash('success', 'Your account has been deleted.');
        Response::redirect('/');
    }
}
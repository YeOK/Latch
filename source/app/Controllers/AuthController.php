<?php

declare(strict_types=1);

namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\Response;

final class AuthController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function showLogin(array $params = []): void
    {
        if ($this->app->auth()->check()) {
            Response::redirect('/');
        }

        if ($this->app->auth()->hasTotpPending()) {
            if ($this->app->auth()->isTotpSetupRequired()) {
                Response::redirect('/login/2fa/setup');
            }
            Response::redirect('/login/2fa');
        }

        $this->app->render('auth/login.html.twig');
    }

    public function login(array $params = []): void
    {
        if (!$this->app->request()->isPost()) {
            Response::redirect('/login');
        }

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            $this->app->session()->flash('error', 'Invalid form token. Please try again.');
            $this->renderLoginFailure();
            return;
        }

        $ip = $this->app->request()->ip();
        $maxAttempts = (int) $this->app->config()->get('security.login_max_attempts', 10);
        $lockoutMinutes = (int) $this->app->config()->get('security.login_lockout_minutes', 15);

        if ($this->app->rateLimiter()->tooManyLoginAttempts($ip, $maxAttempts, $lockoutMinutes)) {
            $this->app->session()->flash('error', 'Too many failed login attempts. Try again later.');
            $this->renderLoginFailure();
            return;
        }

        $username = trim((string) $this->app->request()->input('username', ''));
        $password = (string) $this->app->request()->input('password', '');

        $user = $this->app->users()->findByUsername($username);
        $passwordOk = $user !== null && password_verify($password, (string) $user['password_hash']);

        if ($user !== null && $passwordOk && $this->app->users()->isBanned($user)) {
            $this->app->rateLimiter()->recordLoginAttempt($ip, $username !== '' ? $username : null, false);
            $this->app->securityLog()->log('login_banned', [
                'ip' => $ip,
                'user_id' => (int) $user['id'],
                'username' => $user['username'],
            ]);
            $this->app->session()->flash('error', $this->app->users()->banLoginMessage($user));
            $this->renderLoginFailure();

            return;
        }

        $valid = $passwordOk && !$this->app->users()->isLocked($user);

        $this->app->rateLimiter()->recordLoginAttempt($ip, $username !== '' ? $username : null, $valid);

        if (!$valid) {
            if ($user !== null && $passwordOk) {
                $this->app->users()->recordFailedLogin((int) $user['id'], $maxAttempts, $lockoutMinutes);
                $this->app->session()->flash('error', 'Your account is temporarily locked. Try again later.');
            } else {
                if ($user !== null) {
                    $this->app->users()->recordFailedLogin((int) $user['id'], $maxAttempts, $lockoutMinutes);
                }

                $this->app->session()->flash('error', 'Invalid username or password.');
            }

            $this->app->securityLog()->log('login_fail', [
                'ip' => $ip,
                'username' => $username !== '' ? $username : null,
            ]);
            $this->renderLoginFailure();

            return;
        }

        if ($this->app->requireEmailVerification() && !$this->app->users()->isEmailVerified($user)) {
            $this->app->session()->flash('error', 'Please verify your email before signing in.');
            $this->renderLoginFailure();
            return;
        }

        $twoFactor = $this->app->twoFactor();
        if ($twoFactor->mustEnroll($user)) {
            $this->app->auth()->beginTotpPending($user, true);
            $this->app->securityLog()->log('login_totp_setup_required', [
                'ip' => $ip,
                'user_id' => (int) $user['id'],
            ]);
            Response::redirect('/login/2fa/setup');
        }

        if ($twoFactor->needsChallenge($user)) {
            $this->app->auth()->beginTotpPending($user, false);
            $this->app->securityLog()->log('login_totp_challenge', [
                'ip' => $ip,
                'user_id' => (int) $user['id'],
            ]);
            Response::redirect('/login/2fa');
        }

        $this->app->auth()->login($user);
        $this->app->securityLog()->log('login_success', [
            'ip' => $ip,
            'user_id' => (int) $user['id'],
            'username' => $user['username'],
        ]);
        Response::redirect('/');
    }

    public function showRegister(array $params = []): void
    {
        if ($this->app->auth()->check()) {
            Response::redirect('/');
        }

        if (!$this->app->allowRegistration()) {
            $this->app->session()->flash('error', 'Registration is disabled.');
            Response::redirect('/login');
        }

        $guard = $this->app->registrationGuard();
        $this->app->render('auth/register.html.twig', [
            'registration_turnstile_required' => $guard->turnstileRequired(),
            'turnstile_site_key' => $guard->turnstileSiteKey(),
        ]);
    }

    public function register(array $params = []): void
    {
        if (!$this->app->allowRegistration()) {
            Response::forbidden('Registration is disabled.');
        }

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            $this->app->session()->flash('error', 'Invalid form token. Please try again.');
            Response::redirect('/register');
        }

        $guard = $this->app->registrationGuard();
        $maxPerHour = (int) $this->app->config()->get('security.registration_max_per_ip_hour', 3);

        if ($guard->tooManyAttempts($maxPerHour, 60)) {
            $guard->logBlocked('rate_limit');
            $this->app->session()->flash('error', 'Too many registration attempts from your network. Try again later.');
            Response::redirect('/register');
        }

        if ($guard->honeypotTriggered()) {
            $guard->logBlocked('honeypot');
            $guard->recordAttempt(false);
            $this->fakeRegistrationSuccess();
        }

        if (!$guard->turnstileValid()) {
            $guard->logBlocked('turnstile');
            $guard->recordAttempt(false);
            $this->app->session()->flash('error', 'Please complete the human verification check.');
            Response::redirect('/register');
        }

        $username = trim((string) $this->app->request()->input('username', ''));
        $email = trim((string) $this->app->request()->input('email', ''));
        $password = (string) $this->app->request()->input('password', '');
        $confirm = (string) $this->app->request()->input('password_confirm', '');

        $validator = $this->app->inputValidator();
        foreach ([
            $validator->usernameError($username),
            $validator->emailError($email),
            $validator->passwordError($password),
        ] as $error) {
            if ($error !== null) {
                $this->app->session()->flash('error', $error);
                Response::redirect('/register');
            }
        }

        if (!hash_equals($password, $confirm)) {
            $this->app->session()->flash('error', 'Passwords do not match.');
            Response::redirect('/register');
        }

        if ($this->app->users()->findByUsername($username) !== null) {
            $this->app->session()->flash('error', 'Username is already taken.');
            Response::redirect('/register');
        }

        if ($this->app->users()->findByEmail($email) !== null) {
            $this->app->session()->flash('error', 'Email is already registered.');
            Response::redirect('/register');
        }

        if ($this->app->requireEmailVerification() && !$this->app->mail()->isConfigured()) {
            $this->app->session()->flash(
                'error',
                'Registration is unavailable: email verification is enabled but outbound mail is not configured.'
            );
            Response::redirect('/register');
        }

        $user = $this->app->users()->create(
            $username,
            $email,
            $password,
            'member',
            $this->app->defaultThemeMode(),
        );
        $this->app->fireUserRegister($user);
        $guard->recordAttempt(true);

        if ($this->app->requireEmailVerification()) {
            $token = bin2hex(random_bytes(32));
            $this->app->emailVerifications()->create((int) $user['id'], $email, $token);
            $verifyUrl = $this->app->mail()->siteUrl() . '/verify-email?token=' . rawurlencode($token);
            $sent = $this->app->mail()->send(
                $email,
                'Verify your Latch account',
                "Hello {$username},\n\nVerify your email:\n{$verifyUrl}\n\nThis link expires in 48 hours."
            );
            if (!$sent) {
                $this->app->securityLog()->log('mail_send_failed', [
                    'context' => 'register_verify',
                    'user_id' => (int) $user['id'],
                    'error' => $this->app->mail()->lastError(),
                ]);
                $this->app->session()->flash(
                    'error',
                    'Your account was created but we could not send a verification email. Please contact an administrator.'
                );
                Response::redirect('/login');
            }
            $this->app->session()->flash('success', 'Check your email to verify your account before signing in.');
            Response::redirect('/login');
        }

        $this->app->auth()->login($user);
        $this->app->users()->markEmailVerified((int) $user['id']);
        $this->app->session()->flash('success', 'Welcome to the forum!');
        Response::redirect('/');
    }

    public function showForgotPassword(array $params = []): void
    {
        if ($this->app->auth()->check()) {
            Response::redirect('/');
        }

        $this->app->render('auth/forgot_password.html.twig');
    }

    public function forgotPassword(array $params = []): void
    {
        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            $this->app->session()->flash('error', 'Invalid form token.');
            Response::redirect('/forgot-password');
        }

        $ip = $this->app->request()->ip();
        if ($this->app->rateLimiter()->tooManyLoginAttempts($ip, 5, 15)) {
            $this->app->session()->flash('error', 'Too many requests. Try again later.');
            Response::redirect('/forgot-password');
        }

        $email = strtolower(trim((string) $this->app->request()->input('email', '')));
        $user = $email !== '' ? $this->app->users()->findByEmail($email) : null;

        if ($user !== null) {
            $token = bin2hex(random_bytes(32));
            $this->app->passwordResets()->create((int) $user['id'], $token);
            $resetUrl = $this->app->mail()->siteUrl() . '/reset-password?token=' . rawurlencode($token);
            $sent = $this->app->mail()->send(
                (string) $user['email'],
                'Reset your Latch password',
                "Hello {$user['username']},\n\nReset your password:\n{$resetUrl}\n\nThis link expires in 1 hour."
            );
            if (!$sent) {
                $this->app->securityLog()->log('mail_send_failed', [
                    'context' => 'password_reset',
                    'user_id' => (int) $user['id'],
                    'error' => $this->app->mail()->lastError(),
                ]);
            }
            $this->app->securityLog()->log('password_reset_request', [
                'ip' => $ip,
                'user_id' => (int) $user['id'],
            ]);
        }

        $this->app->rateLimiter()->recordLoginAttempt($ip, $email !== '' ? $email : null, false);
        $this->app->session()->flash('success', 'If that email is registered, a reset link has been sent.');
        Response::redirect('/login');
    }

    public function showResetPassword(array $params = []): void
    {
        $token = (string) $this->app->request()->input('token', '');
        if ($token === '' || $this->app->passwordResets()->findValid($token) === null) {
            $this->app->session()->flash('error', 'Invalid or expired reset link.');
            Response::redirect('/login');
        }

        $this->app->render('auth/reset_password.html.twig', ['token' => $token]);
    }

    public function resetPassword(array $params = []): void
    {
        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            $this->app->session()->flash('error', 'Invalid form token.');
            Response::redirect('/login');
        }

        $token = (string) $this->app->request()->input('token', '');
        $reset = $this->app->passwordResets()->findValid($token);
        if ($reset === null) {
            $this->app->session()->flash('error', 'Invalid or expired reset link.');
            Response::redirect('/login');
        }

        $password = (string) $this->app->request()->input('password', '');
        $confirm = (string) $this->app->request()->input('password_confirm', '');
        $minLength = (int) $this->app->config()->get('security.password_min_length', 8);

        if (strlen($password) < $minLength) {
            $this->app->session()->flash('error', "Password must be at least {$minLength} characters.");
            Response::redirect('/reset-password?token=' . rawurlencode($token));
        }

        if (!hash_equals($password, $confirm)) {
            $this->app->session()->flash('error', 'Passwords do not match.');
            Response::redirect('/reset-password?token=' . rawurlencode($token));
        }

        $this->app->users()->updatePassword((int) $reset['user_id'], $password);
        $this->app->passwordResets()->markUsed((int) $reset['id']);
        $this->app->userSessions()->revokeAllForUser((int) $reset['user_id']);
        $this->app->securityLog()->log('password_reset_complete', [
            'ip' => $this->app->request()->ip(),
            'user_id' => (int) $reset['user_id'],
        ]);

        $this->app->session()->flash('success', 'Password updated. You can sign in now.');
        Response::redirect('/login');
    }

    public function verifyEmail(array $params = []): void
    {
        $token = (string) $this->app->request()->input('token', '');
        $verification = $this->app->emailVerifications()->findValid($token);

        if ($verification === null) {
            $this->app->session()->flash('error', 'Invalid or expired verification link.');
            Response::redirect('/login');
        }

        $this->app->users()->markEmailVerified((int) $verification['user_id']);
        $this->app->emailVerifications()->markVerified((int) $verification['id']);
        $this->app->session()->flash('success', 'Email verified. You can sign in now.');
        Response::redirect('/login');
    }

    public function confirmEmailChange(array $params = []): void
    {
        $token = (string) $this->app->request()->input('token', '');
        $change = $this->app->emailChanges()->findValid($token);

        if ($change === null) {
            $this->app->session()->flash('error', 'Invalid or expired email change link.');
            Response::redirect($this->app->auth()->check() ? '/profile' : '/login');
        }

        $loggedInAsUser = $this->app->auth()->check()
            && (int) ($this->app->auth()->user()['id'] ?? 0) === (int) $change['user_id'];
        $redirectAfter = $loggedInAsUser ? '/profile' : '/login';

        $userId = (int) $change['user_id'];
        $newEmail = (string) $change['new_email'];

        if ($this->app->users()->findByEmail($newEmail) !== null) {
            $this->app->emailChanges()->markUsed((int) $change['id']);
            $this->app->session()->flash('error', 'That email address is already in use.');
            Response::redirect($redirectAfter);
        }

        $this->app->users()->updateEmail($userId, $newEmail);
        $this->app->emailChanges()->markUsed((int) $change['id']);
        $this->app->emailChanges()->invalidateForUser($userId);

        if ($loggedInAsUser) {
            $this->app->userSessions()->revokeAllExcept($userId, $this->app->session()->id());
        } else {
            $this->app->userSessions()->revokeAllForUser($userId);
        }

        $this->app->securityLog()->log('email_change_complete', [
            'ip' => $this->app->request()->ip(),
            'user_id' => $userId,
        ]);

        if ($loggedInAsUser) {
            $this->app->session()->flash('success', 'Your email address has been updated.');
            Response::redirect('/profile');
        }

        $this->app->session()->flash('success', 'Email address updated. Please sign in again.');
        Response::redirect('/login');
    }

    public function logout(array $params = []): void
    {
        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user !== null) {
            $this->app->securityLog()->log('logout', [
                'ip' => $this->app->request()->ip(),
                'user_id' => (int) $user['id'],
            ]);
        }

        $this->app->auth()->logout();
        Response::redirect('/');
    }

    private function fakeRegistrationSuccess(): void
    {
        if ($this->app->requireEmailVerification()) {
            $this->app->session()->flash('success', 'Check your email to verify your account before signing in.');
            Response::redirect('/login');
        }

        $this->app->session()->flash('success', 'Welcome to the forum!');
        Response::redirect('/login');
    }

    private function renderLoginFailure(): void
    {
        // Return 200 on failure so fail2ban can detect brute-force attempts.
        $this->app->render('auth/login.html.twig');
    }
}
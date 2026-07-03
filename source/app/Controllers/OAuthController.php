<?php

declare(strict_types=1);

namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\OAuthScopes;
use Latch\Core\Response;

final class OAuthController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function token(array $params = []): void
    {
        if (!$this->app->request()->isPost()) {
            $this->oauthError('invalid_request', 'POST required.', 405);
        }

        $grantType = strtolower(trim((string) $this->app->request()->input('grant_type', '')));
        $clientId = trim((string) $this->app->request()->input('client_id', ''));

        if ($clientId === '') {
            $this->oauthError('invalid_request', 'client_id is required.', 400);
        }

        $client = $this->app->oauthClients()->findActiveByClientId($clientId);
        if ($client === null) {
            $this->oauthError('invalid_client', 'Unknown or revoked client.', 401);
        }

        if ((int) ($client['is_confidential'] ?? 1) === 1) {
            $secret = (string) $this->app->request()->input('client_secret', '');
            if ($secret === '' || !$this->app->oauthClients()->verifySecret($client, $secret)) {
                $this->oauthError('invalid_client', 'Invalid client credentials.', 401);
            }
        }

        match ($grantType) {
            'client_credentials' => $this->tokenClientCredentials($client),
            'authorization_code' => $this->tokenAuthorizationCode($client),
            'refresh_token' => $this->tokenRefresh($client),
            default => $this->oauthError('unsupported_grant_type', 'Grant type is not supported.', 400),
        };
    }

    public function showAuthorize(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        $clientId = trim((string) $this->app->request()->input('client_id', ''));
        $redirectUri = trim((string) $this->app->request()->input('redirect_uri', ''));
        $responseType = trim((string) $this->app->request()->input('response_type', ''));
        $state = trim((string) $this->app->request()->input('state', ''));
        $scope = trim((string) $this->app->request()->input('scope', OAuthScopes::READ));
        $codeChallenge = trim((string) $this->app->request()->input('code_challenge', ''));
        $codeChallengeMethod = trim((string) $this->app->request()->input('code_challenge_method', 'S256'));

        if ($responseType !== 'code') {
            $this->oauthError('unsupported_response_type', 'Only response_type=code is supported.', 400);
        }

        $client = $this->validateAuthorizeClient($clientId, $redirectUri);
        if ($codeChallenge === '' || !in_array($codeChallengeMethod, ['S256', 'plain'], true)) {
            $this->oauthError('invalid_request', 'PKCE code_challenge is required.', 400);
        }

        $requestedScopes = OAuthScopes::parseScopeString($scope);
        $grantedScopes = OAuthScopes::intersect(
            $this->app->oauthClients()->scopes($client),
            $requestedScopes,
        );
        if ($grantedScopes === []) {
            $this->oauthError('invalid_scope', 'Requested scope is not allowed for this client.', 400);
        }

        $scopeLabels = [];
        foreach ($grantedScopes as $scope) {
            $scopeLabels[$scope] = OAuthScopes::label($scope);
        }

        $this->app->render('oauth/authorize.html.twig', [
            'client_name' => (string) $client['name'],
            'scopes' => $grantedScopes,
            'scope_labels' => $scopeLabels,
            'state' => $state,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => OAuthScopes::toString($grantedScopes),
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
        ]);
    }

    public function cliCallback(array $params = []): void
    {
        $code = trim((string) $this->app->request()->input('code', ''));
        $state = trim((string) $this->app->request()->input('state', ''));
        $error = trim((string) $this->app->request()->input('error', ''));
        $errorDescription = trim((string) $this->app->request()->input('error_description', ''));

        $this->app->render('oauth/cli_callback.html.twig', [
            'code' => $code,
            'state' => $state,
            'error' => $error,
            'error_description' => $errorDescription,
        ]);
    }

    public function approveAuthorize(array $params = []): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            $this->app->session()->flash('error', 'Invalid form token.');
            Response::redirect('/oauth/authorize?' . $this->authorizeQueryFromRequest());
        }

        $clientId = trim((string) $this->app->request()->input('client_id', ''));
        $redirectUri = trim((string) $this->app->request()->input('redirect_uri', ''));
        $state = trim((string) $this->app->request()->input('state', ''));
        $scope = trim((string) $this->app->request()->input('scope', OAuthScopes::READ));
        $codeChallenge = trim((string) $this->app->request()->input('code_challenge', ''));
        $codeChallengeMethod = trim((string) $this->app->request()->input('code_challenge_method', 'S256'));
        $decision = strtolower(trim((string) $this->app->request()->input('decision', '')));

        $client = $this->validateAuthorizeClient($clientId, $redirectUri);
        $grantedScopes = OAuthScopes::intersect(
            $this->app->oauthClients()->scopes($client),
            OAuthScopes::parseScopeString($scope),
        );

        if ($decision !== 'approve') {
            $this->redirectWithOAuthError($redirectUri, 'access_denied', 'User denied the request.', $state);
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $code = $this->app->oauthTokens()->storeAuthorizationCode(
            $clientId,
            (int) $user['id'],
            $grantedScopes,
            $redirectUri,
            $codeChallenge,
            $codeChallengeMethod,
        );

        $query = http_build_query(array_filter([
            'code' => $code,
            'state' => $state !== '' ? $state : null,
        ]));

        Response::redirect($redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . $query);
    }

    /**
     * @param array<string, mixed> $client
     */
    private function tokenClientCredentials(array $client): void
    {
        $scopeInput = trim((string) $this->app->request()->input('scope', OAuthScopes::READ));
        $scopes = OAuthScopes::filterForClientCredentials(
            OAuthScopes::intersect(
                $this->app->oauthClients()->scopes($client),
                OAuthScopes::parseScopeString($scopeInput),
            ),
        );
        if ($scopes === []) {
            $this->oauthError('invalid_scope', 'Requested scope is not allowed for client credentials.', 400);
        }

        $payload = $this->app->oauthTokens()->issueClientCredentialsToken(
            (string) $client['client_id'],
            $scopes,
        );

        Response::json($payload);
    }

    /**
     * @param array<string, mixed> $client
     */
    private function tokenAuthorizationCode(array $client): void
    {
        $code = trim((string) $this->app->request()->input('code', ''));
        $redirectUri = trim((string) $this->app->request()->input('redirect_uri', ''));
        $codeVerifier = trim((string) $this->app->request()->input('code_verifier', ''));

        if ($code === '' || $redirectUri === '' || $codeVerifier === '') {
            $this->oauthError('invalid_request', 'code, redirect_uri, and code_verifier are required.', 400);
        }

        if (!$this->app->oauthClients()->allowsRedirectUri($client, $redirectUri)) {
            $this->oauthError('invalid_grant', 'redirect_uri does not match.', 400);
        }

        $consumed = $this->app->oauthTokens()->consumeAuthorizationCode(
            $code,
            (string) $client['client_id'],
            $redirectUri,
            $codeVerifier,
        );
        if ($consumed === null) {
            $this->oauthError('invalid_grant', 'Authorization code is invalid or expired.', 400);
        }

        $payload = $this->app->oauthTokens()->issueAuthorizationCodeTokens(
            (string) $client['client_id'],
            (int) $consumed['user_id'],
            $consumed['scopes'],
        );

        Response::json($payload);
    }

    /**
     * @param array<string, mixed> $client
     */
    private function tokenRefresh(array $client): void
    {
        $refreshToken = trim((string) $this->app->request()->input('refresh_token', ''));
        if ($refreshToken === '') {
            $this->oauthError('invalid_request', 'refresh_token is required.', 400);
        }

        $pair = $this->app->oauthTokens()->findValidRefreshToken(
            $refreshToken,
            (string) $client['client_id'],
        );
        if ($pair === null) {
            $this->oauthError('invalid_grant', 'Refresh token is invalid or expired.', 400);
        }

        $payload = $this->app->oauthTokens()->rotateRefreshToken($pair['refresh']);
        Response::json($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAuthorizeClient(string $clientId, string $redirectUri): array
    {
        if ($clientId === '' || $redirectUri === '') {
            $this->oauthError('invalid_request', 'client_id and redirect_uri are required.', 400);
        }

        $client = $this->app->oauthClients()->findActiveByClientId($clientId);
        if ($client === null) {
            $this->oauthError('invalid_client', 'Unknown or revoked client.', 400);
        }

        if (!$this->app->oauthClients()->allowsRedirectUri($client, $redirectUri)) {
            $this->oauthError('invalid_request', 'redirect_uri is not registered for this client.', 400);
        }

        return $client;
    }

    private function authorizeQueryFromRequest(): string
    {
        return http_build_query(array_filter([
            'response_type' => $this->app->request()->input('response_type', 'code'),
            'client_id' => $this->app->request()->input('client_id'),
            'redirect_uri' => $this->app->request()->input('redirect_uri'),
            'scope' => $this->app->request()->input('scope'),
            'state' => $this->app->request()->input('state'),
            'code_challenge' => $this->app->request()->input('code_challenge'),
            'code_challenge_method' => $this->app->request()->input('code_challenge_method'),
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    private function redirectWithOAuthError(
        string $redirectUri,
        string $error,
        string $description,
        string $state,
    ): void {
        $query = http_build_query(array_filter([
            'error' => $error,
            'error_description' => $description,
            'state' => $state !== '' ? $state : null,
        ]));
        Response::redirect($redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . $query);
    }

    private function oauthError(string $error, string $description, int $status): void
    {
        Response::json([
            'error' => $error,
            'error_description' => $description,
        ], $status);
    }
}
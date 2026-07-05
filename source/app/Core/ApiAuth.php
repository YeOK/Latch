<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use Latch\Models\OAuthClientRepository;
use Latch\Models\OAuthTokenRepository;
use Latch\Models\UserRepository;

/**
 * Resolves Bearer tokens and optional guest access for API routes.
 */
final class ApiAuth
{
    public function __construct(
        private readonly Request $request,
        private readonly OAuthTokenRepository $tokens,
        private readonly OAuthClientRepository $clients,
        private readonly UserRepository $users,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function resolve(): ApiContext
    {
        $plain = $this->request->bearerToken();
        if ($plain === null) {
            return new ApiContext(
                null,
                [OAuthScopes::READ],
                null,
                'ip:' . $this->request->ip(),
            );
        }

        $row = $this->tokens->findValidAccessToken($plain);
        if ($row === null) {
            ApiResponse::error('invalid_token', 'Bearer token is invalid or expired.', 401);
        }

        $client = $this->clients->findActiveByClientId((string) $row['client_id']);
        if ($client === null) {
            ApiResponse::error('invalid_client', 'OAuth client is revoked or missing.', 401);
        }

        $user = null;
        $userId = $row['user_id'] ?? null;
        if ($userId !== null) {
            $user = $this->users->findById((int) $userId);
            if ($user === null || $this->users->isDeleted($user) || $this->users->isBanned($user) || $this->users->isLocked($user)) {
                ApiResponse::error('invalid_token', 'Token user is no longer active.', 401);
            }
        }

        $scopes = $this->tokens->tokenScopes($row);
        $rateLimit = max(10, (int) ($client['rate_limit_per_minute'] ?? 60));
        if ($this->rateLimiter->tooManyApiRequests((string) $client['client_id'], $rateLimit, 1)) {
            ApiResponse::error('rate_limited', 'API rate limit exceeded for this client.', 429);
        }
        $this->rateLimiter->recordApiRequest((string) $client['client_id']);

        return new ApiContext(
            (string) $client['client_id'],
            $scopes,
            $user,
            'client:' . (string) $client['client_id'],
        );
    }

    public function enforceGuestRateLimit(int $maxPerMinute = 60): void
    {
        $bucket = 'ip:' . $this->request->ip();
        if ($this->rateLimiter->tooManyApiRequests($bucket, $maxPerMinute, 1)) {
            ApiResponse::error('rate_limited', 'API rate limit exceeded.', 429);
        }
        $this->rateLimiter->recordApiRequest($bucket);
    }
}
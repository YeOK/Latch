<?php

declare(strict_types=1);

namespace Latch\Core;

/**
 * Resolved identity for an API request (guest, client, or user-delegated).
 */
final class ApiContext
{
    /**
     * @param list<string> $scopes
     * @param array<string, mixed>|null $user
     */
    public function __construct(
        public readonly ?string $clientId,
        public readonly array $scopes,
        public readonly ?array $user,
        public readonly string $rateBucket,
    ) {
    }

    public function isLoggedIn(): bool
    {
        return $this->user !== null;
    }

    public function userRole(): ?string
    {
        if ($this->user === null) {
            return null;
        }

        return (string) ($this->user['role'] ?? Auth::ROLE_MEMBER);
    }

    public function isMod(): bool
    {
        $role = $this->userRole();

        return in_array($role, [Auth::ROLE_ADMIN, Auth::ROLE_MOD], true);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function userId(): ?int
    {
        return $this->user !== null ? (int) $this->user['id'] : null;
    }
}
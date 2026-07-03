<?php

declare(strict_types=1);

namespace Latch\Core;

/**
 * OAuth scope definitions for the Latch API.
 */
final class OAuthScopes
{
    public const READ = 'read';
    public const MESSAGES_READ = 'messages:read';
    public const MESSAGES_WRITE = 'messages:write';

    /** @var list<string> */
    public const ALL = [
        self::READ,
        self::MESSAGES_READ,
        self::MESSAGES_WRITE,
    ];

    /** Scopes allowed for client_credentials grants (machine / guest-level reads). */
    /** @var list<string> */
    public const CLIENT_CREDENTIALS_ALLOWED = [
        self::READ,
    ];

    /** Scopes that require a user-delegated token (authorization_code + PKCE). */
    /** @var list<string> */
    public const USER_DELEGATED_ONLY = [
        self::MESSAGES_READ,
        self::MESSAGES_WRITE,
    ];

    /**
     * @param list<string> $requested
     * @return list<string>
     */
    public static function normalize(array $requested): array
    {
        $scopes = [];
        foreach ($requested as $scope) {
            $scope = strtolower(trim($scope));
            if ($scope === '' || !in_array($scope, self::ALL, true)) {
                continue;
            }
            $scopes[$scope] = true;
        }

        if ($scopes === []) {
            return [self::READ];
        }

        return array_keys($scopes);
    }

    /**
     * @return list<string>
     */
    public static function parseScopeString(string $scopeString): array
    {
        if (trim($scopeString) === '') {
            return [self::READ];
        }

        $parts = preg_split('/\s+/', trim($scopeString)) ?: [];

        return self::normalize($parts);
    }

    /**
     * @param list<string> $granted
     * @param list<string> $requested
     * @return list<string>
     */
    public static function intersect(array $granted, array $requested): array
    {
        $grantedMap = array_fill_keys(self::normalize($granted), true);
        $result = [];
        foreach (self::knownScopesOnly($requested) as $scope) {
            if (isset($grantedMap[$scope])) {
                $result[] = $scope;
            }
        }

        return $result;
    }

    /**
     * @param list<string> $scopes
     * @return list<string>
     */
    private static function knownScopesOnly(array $scopes): array
    {
        $known = [];
        foreach ($scopes as $scope) {
            $scope = strtolower(trim($scope));
            if ($scope !== '' && in_array($scope, self::ALL, true)) {
                $known[$scope] = true;
            }
        }

        return array_keys($known);
    }

    /**
     * @param list<string> $scopes
     * @return list<string>
     */
    public static function filterForClientCredentials(array $scopes): array
    {
        return self::intersect($scopes, self::CLIENT_CREDENTIALS_ALLOWED);
    }

    public static function isUserDelegatedOnly(string $scope): bool
    {
        return in_array($scope, self::USER_DELEGATED_ONLY, true);
    }

    public static function label(string $scope): string
    {
        return match ($scope) {
            self::READ => 'Read boards, topics, posts, and public profiles (respecting your permissions)',
            self::MESSAGES_READ => 'Read your direct message inbox and threads',
            self::MESSAGES_WRITE => 'Send direct messages and start conversations',
            default => $scope,
        };
    }

    public static function toString(array $scopes): string
    {
        return implode(' ', self::normalize($scopes));
    }
}
<?php

declare(strict_types=1);

namespace Latch\Models;

use Latch\Core\Database;
use Latch\Core\OAuthScopes;
use RuntimeException;

final class OAuthTokenRepository
{
    public const ACCESS_TTL_SECONDS = 3600;
    public const REFRESH_TTL_SECONDS = 2592000;
    public const CODE_TTL_SECONDS = 600;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param list<string> $scopes
     * @return array{access_token: string, token_type: string, expires_in: int, scope: string}
     */
    public function issueClientCredentialsToken(string $clientId, array $scopes): array
    {
        $plain = $this->generateToken('latch_at_');
        $expiresAt = gmdate('c', time() + self::ACCESS_TTL_SECONDS);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO oauth_access_tokens (token_hash, client_id, user_id, scopes, expires_at, created_at)
             VALUES (:token_hash, :client_id, NULL, :scopes, :expires_at, :created_at)'
        );
        $stmt->execute([
            'token_hash' => self::hashToken($plain),
            'client_id' => $clientId,
            'scopes' => json_encode(OAuthScopes::normalize($scopes), JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt,
            'created_at' => gmdate('c'),
        ]);

        return [
            'access_token' => $plain,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL_SECONDS,
            'scope' => OAuthScopes::toString($scopes),
        ];
    }

    /**
     * @param list<string> $scopes
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string}
     */
    public function issueAuthorizationCodeTokens(
        string $clientId,
        int $userId,
        array $scopes,
    ): array {
        $accessPlain = $this->generateToken('latch_at_');
        $refreshPlain = $this->generateToken('latch_rt_');
        $expiresAt = gmdate('c', time() + self::ACCESS_TTL_SECONDS);
        $refreshExpiresAt = gmdate('c', time() + self::REFRESH_TTL_SECONDS);
        $scopes = OAuthScopes::normalize($scopes);
        $now = gmdate('c');

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO oauth_access_tokens (token_hash, client_id, user_id, scopes, expires_at, created_at)
                 VALUES (:token_hash, :client_id, :user_id, :scopes, :expires_at, :created_at)'
            );
            $stmt->execute([
                'token_hash' => self::hashToken($accessPlain),
                'client_id' => $clientId,
                'user_id' => $userId,
                'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
                'expires_at' => $expiresAt,
                'created_at' => $now,
            ]);
            $accessTokenId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO oauth_refresh_tokens (
                    token_hash, access_token_id, client_id, user_id, scopes, expires_at, created_at
                 ) VALUES (
                    :token_hash, :access_token_id, :client_id, :user_id, :scopes, :expires_at, :created_at
                 )'
            );
            $stmt->execute([
                'token_hash' => self::hashToken($refreshPlain),
                'access_token_id' => $accessTokenId,
                'client_id' => $clientId,
                'user_id' => $userId,
                'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
                'expires_at' => $refreshExpiresAt,
                'created_at' => $now,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        return [
            'access_token' => $accessPlain,
            'refresh_token' => $refreshPlain,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL_SECONDS,
            'scope' => OAuthScopes::toString($scopes),
        ];
    }

    /**
     * @param list<string> $scopes
     */
    public function storeAuthorizationCode(
        string $clientId,
        int $userId,
        array $scopes,
        string $redirectUri,
        string $codeChallenge,
        string $codeChallengeMethod,
    ): string {
        $plain = $this->generateToken('latch_ac_');
        $expiresAt = gmdate('c', time() + self::CODE_TTL_SECONDS);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO oauth_authorization_codes (
                code_hash, client_id, user_id, scopes, redirect_uri,
                code_challenge, code_challenge_method, expires_at, created_at
             ) VALUES (
                :code_hash, :client_id, :user_id, :scopes, :redirect_uri,
                :code_challenge, :code_challenge_method, :expires_at, :created_at
             )'
        );
        $stmt->execute([
            'code_hash' => self::hashToken($plain),
            'client_id' => $clientId,
            'user_id' => $userId,
            'scopes' => json_encode(OAuthScopes::normalize($scopes), JSON_THROW_ON_ERROR),
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'expires_at' => $expiresAt,
            'created_at' => gmdate('c'),
        ]);

        return $plain;
    }

    public function consumeAuthorizationCode(
        string $code,
        string $clientId,
        string $redirectUri,
        string $codeVerifier,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM oauth_authorization_codes
             WHERE code_hash = :code_hash AND client_id = :client_id AND used_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            'code_hash' => self::hashToken($code),
            'client_id' => $clientId,
        ]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        if ((string) $row['redirect_uri'] !== $redirectUri) {
            return null;
        }

        if (!$this->verifyPkce(
            (string) $row['code_challenge'],
            (string) $row['code_challenge_method'],
            $codeVerifier,
        )) {
            return null;
        }

        $update = $this->db->pdo()->prepare(
            'UPDATE oauth_authorization_codes SET used_at = :used_at WHERE id = :id AND used_at IS NULL'
        );
        $update->execute([
            'used_at' => gmdate('c'),
            'id' => (int) $row['id'],
        ]);
        if ($update->rowCount() === 0) {
            return null;
        }

        $scopes = json_decode((string) $row['scopes'], true);

        return [
            'user_id' => (int) $row['user_id'],
            'scopes' => OAuthScopes::normalize(is_array($scopes) ? $scopes : []),
        ];
    }

    public function findValidAccessToken(string $plainToken): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM oauth_access_tokens
             WHERE token_hash = :token_hash AND revoked_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => self::hashToken($plainToken)]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        return $row;
    }

    /**
     * @return array{refresh: array<string, mixed>, access: array<string, mixed>}|null
     */
    public function findValidRefreshToken(string $plainToken, string $clientId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM oauth_refresh_tokens
             WHERE token_hash = :token_hash AND client_id = :client_id AND revoked_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            'token_hash' => self::hashToken($plainToken),
            'client_id' => $clientId,
        ]);
        $refresh = $stmt->fetch();
        if ($refresh === false) {
            return null;
        }

        if (strtotime((string) $refresh['expires_at']) < time()) {
            return null;
        }

        $accessStmt = $this->db->pdo()->prepare(
            'SELECT * FROM oauth_access_tokens WHERE id = :id LIMIT 1'
        );
        $accessStmt->execute(['id' => (int) $refresh['access_token_id']]);
        $access = $accessStmt->fetch();

        return $access === false ? null : [
            'refresh' => $refresh,
            'access' => $access,
        ];
    }

    public function revokeAccessToken(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE oauth_access_tokens SET revoked_at = :revoked_at WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute([
            'revoked_at' => gmdate('c'),
            'id' => $id,
        ]);
    }

    /**
     * @param list<string> $scopes
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string}
     */
    public function rotateRefreshToken(array $refreshRow): array
    {
        $this->revokeAccessToken((int) $refreshRow['access_token_id']);

        $stmt = $this->db->pdo()->prepare(
            'UPDATE oauth_refresh_tokens SET revoked_at = :revoked_at WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute([
            'revoked_at' => gmdate('c'),
            'id' => (int) $refreshRow['id'],
        ]);

        $scopes = json_decode((string) $refreshRow['scopes'], true);

        return $this->issueAuthorizationCodeTokens(
            (string) $refreshRow['client_id'],
            (int) $refreshRow['user_id'],
            OAuthScopes::normalize(is_array($scopes) ? $scopes : []),
        );
    }

    /**
     * Active user-delegated OAuth apps (one row per client, most recent grant).
     *
     * @return list<array{client_id: string, client_name: string, authorized_at: string, scopes: list<string>}>
     */
    public function listAuthorizedAppsForUser(int $userId): array
    {
        $now = gmdate('c');
        $stmt = $this->db->pdo()->prepare(
            'SELECT rt.client_id, rt.scopes, rt.created_at, c.name AS client_name
             FROM oauth_refresh_tokens rt
             INNER JOIN oauth_clients c ON c.client_id = rt.client_id AND c.revoked_at IS NULL
             WHERE rt.user_id = :user_id
               AND rt.revoked_at IS NULL
               AND rt.expires_at > :now
             ORDER BY rt.created_at DESC'
        );
        $stmt->execute([
            'user_id' => $userId,
            'now' => $now,
        ]);

        /** @var array<string, array{client_id: string, client_name: string, authorized_at: string, scopes: list<string>}> $byClient */
        $byClient = [];
        foreach ($stmt->fetchAll() as $row) {
            $clientId = (string) $row['client_id'];
            if (isset($byClient[$clientId])) {
                continue;
            }

            $byClient[$clientId] = [
                'client_id' => $clientId,
                'client_name' => (string) $row['client_name'],
                'authorized_at' => (string) $row['created_at'],
                'scopes' => $this->tokenScopes($row),
            ];
        }

        return array_values($byClient);
    }

    public function userHasActiveDelegation(int $userId, string $clientId): bool
    {
        $clientId = trim($clientId);
        if ($clientId === '') {
            return false;
        }

        $now = gmdate('c');
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM oauth_refresh_tokens
             WHERE user_id = :user_id AND client_id = :client_id
               AND revoked_at IS NULL AND expires_at > :now
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'client_id' => $clientId,
            'now' => $now,
        ]);

        return $stmt->fetch() !== false;
    }

    /**
     * Revoke all user-delegated tokens for an OAuth client (access, refresh, pending codes).
     */
    public function revokeUserDelegation(int $userId, string $clientId): int
    {
        $clientId = trim($clientId);
        if ($clientId === '') {
            return 0;
        }

        $now = gmdate('c');
        $pdo = $this->db->pdo();
        $count = 0;

        $stmt = $pdo->prepare(
            'UPDATE oauth_access_tokens SET revoked_at = :revoked_at
             WHERE user_id = :user_id AND client_id = :client_id AND revoked_at IS NULL'
        );
        $stmt->execute([
            'revoked_at' => $now,
            'user_id' => $userId,
            'client_id' => $clientId,
        ]);
        $count += $stmt->rowCount();

        $stmt = $pdo->prepare(
            'UPDATE oauth_refresh_tokens SET revoked_at = :revoked_at
             WHERE user_id = :user_id AND client_id = :client_id AND revoked_at IS NULL'
        );
        $stmt->execute([
            'revoked_at' => $now,
            'user_id' => $userId,
            'client_id' => $clientId,
        ]);
        $count += $stmt->rowCount();

        $stmt = $pdo->prepare(
            'UPDATE oauth_authorization_codes SET used_at = :used_at
             WHERE user_id = :user_id AND client_id = :client_id AND used_at IS NULL'
        );
        $stmt->execute([
            'used_at' => $now,
            'user_id' => $userId,
            'client_id' => $clientId,
        ]);
        $count += $stmt->rowCount();

        return $count;
    }

    public function pruneExpired(int $olderThanDays = 30): int
    {
        $cutoff = gmdate('c', time() - ($olderThanDays * 86400));
        $pdo = $this->db->pdo();
        $count = 0;

        foreach (['oauth_authorization_codes', 'oauth_access_tokens', 'oauth_refresh_tokens'] as $table) {
            $stmt = $pdo->prepare("DELETE FROM {$table} WHERE expires_at < :cutoff");
            $stmt->execute(['cutoff' => $cutoff]);
            $count += $stmt->rowCount();
        }

        $stmt = $pdo->prepare('DELETE FROM oauth_authorization_codes WHERE used_at IS NOT NULL AND used_at < :cutoff');
        $stmt->execute(['cutoff' => $cutoff]);
        $count += $stmt->rowCount();

        return $count;
    }

    /**
     * @return list<string>
     */
    public function tokenScopes(array $tokenRow): array
    {
        $raw = json_decode((string) ($tokenRow['scopes'] ?? '[]'), true);

        return OAuthScopes::normalize(is_array($raw) ? $raw : []);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function generateToken(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(24));
    }

    private function verifyPkce(string $challenge, string $method, string $verifier): bool
    {
        if ($method !== 'S256' && $method !== 'plain') {
            return false;
        }

        if ($method === 'plain') {
            return hash_equals($challenge, $verifier);
        }

        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $computed);
    }
}
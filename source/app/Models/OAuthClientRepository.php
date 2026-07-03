<?php

declare(strict_types=1);

namespace Latch\Models;

use Latch\Core\Database;
use Latch\Core\OAuthScopes;
use RuntimeException;

final class OAuthClientRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findByClientId(string $clientId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM oauth_clients WHERE client_id = :client_id LIMIT 1'
        );
        $stmt->execute(['client_id' => $clientId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findActiveByClientId(string $clientId): ?array
    {
        $client = $this->findByClientId($clientId);
        if ($client === null || ($client['revoked_at'] ?? null) !== null) {
            return null;
        }

        return $client;
    }

    /**
     * @param list<string> $redirectUris
     * @param list<string> $scopes
     * @return array{client: array<string, mixed>, client_secret: ?string}
     */
    public function create(
        string $name,
        array $redirectUris,
        array $scopes,
        bool $isConfidential,
        ?int $createdByUserId,
        int $rateLimitPerMinute = 60,
    ): array {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Client name is required.');
        }

        if (!$isConfidential && $redirectUris === []) {
            throw new RuntimeException('Public clients require at least one redirect URI.');
        }

        $clientId = 'latch_' . bin2hex(random_bytes(12));
        $clientSecret = $isConfidential ? bin2hex(random_bytes(32)) : null;
        $scopes = OAuthScopes::normalize($scopes);
        $redirectUris = array_values(array_filter(array_map('trim', $redirectUris)));

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO oauth_clients (
                client_id, client_secret_hash, name, redirect_uris, scopes,
                rate_limit_per_minute, is_confidential, created_by_user_id, created_at
             ) VALUES (
                :client_id, :client_secret_hash, :name, :redirect_uris, :scopes,
                :rate_limit, :is_confidential, :created_by_user_id, :created_at
             )'
        );
        $stmt->execute([
            'client_id' => $clientId,
            'client_secret_hash' => $clientSecret !== null ? self::hashSecret($clientSecret) : null,
            'name' => $name,
            'redirect_uris' => json_encode($redirectUris, JSON_THROW_ON_ERROR),
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'rate_limit' => max(10, min(600, $rateLimitPerMinute)),
            'is_confidential' => $isConfidential ? 1 : 0,
            'created_by_user_id' => $createdByUserId,
            'created_at' => gmdate('c'),
        ]);

        $client = $this->findByClientId($clientId);
        if ($client === null) {
            throw new RuntimeException('Failed to create OAuth client.');
        }

        return [
            'client' => $client,
            'client_secret' => $clientSecret,
        ];
    }

    public function verifySecret(array $client, string $secret): bool
    {
        $hash = $client['client_secret_hash'] ?? null;
        if ($hash === null || $hash === '') {
            return false;
        }

        return hash_equals((string) $hash, self::hashSecret($secret));
    }

    public function revoke(string $clientId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE oauth_clients SET revoked_at = :revoked_at WHERE client_id = :client_id AND revoked_at IS NULL'
        );
        $stmt->execute([
            'client_id' => $clientId,
            'revoked_at' => gmdate('c'),
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->db->pdo()->query(
            'SELECT c.*, u.username AS created_by_username
             FROM oauth_clients c
             LEFT JOIN users u ON u.id = c.created_by_user_id
             ORDER BY c.created_at DESC'
        )->fetchAll();
    }

    /**
     * @return list<string>
     */
    public function redirectUris(array $client): array
    {
        $raw = json_decode((string) ($client['redirect_uris'] ?? '[]'), true);

        return is_array($raw) ? array_values(array_filter(array_map('strval', $raw))) : [];
    }

    /**
     * @return list<string>
     */
    public function scopes(array $client): array
    {
        $raw = json_decode((string) ($client['scopes'] ?? '[]'), true);

        return OAuthScopes::normalize(is_array($raw) ? $raw : []);
    }

    public function allowsRedirectUri(array $client, string $redirectUri): bool
    {
        $redirectUri = trim($redirectUri);
        foreach ($this->redirectUris($client) as $allowed) {
            if ($allowed === $redirectUri) {
                return true;
            }
        }

        return false;
    }

    public function addRedirectUri(string $clientId, string $redirectUri): bool
    {
        $redirectUri = trim($redirectUri);
        if ($redirectUri === '') {
            return false;
        }

        $client = $this->findActiveByClientId($clientId);
        if ($client === null) {
            return false;
        }

        $uris = $this->redirectUris($client);
        if (in_array($redirectUri, $uris, true)) {
            return true;
        }

        $uris[] = $redirectUri;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE oauth_clients SET redirect_uris = :redirect_uris WHERE client_id = :client_id'
        );
        $stmt->execute([
            'redirect_uris' => json_encode(array_values($uris), JSON_THROW_ON_ERROR),
            'client_id' => $clientId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
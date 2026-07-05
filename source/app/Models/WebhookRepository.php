<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Models;

use Latch\Core\Database;
use Latch\Core\Webhooks\WebhookEvent;

final class WebhookRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT * FROM webhooks ORDER BY id ASC'
        );

        return $stmt->fetchAll() ?: [];
    }

    /** @return list<array<string, mixed>> */
    public function listEnabledForEvent(string $event): array
    {
        if (!WebhookEvent::isValid($event)) {
            return [];
        }

        $rows = $this->listAll();
        $matched = [];
        foreach ($rows as $row) {
            if (!(bool) ($row['enabled'] ?? 0)) {
                continue;
            }
            $events = json_decode((string) ($row['events'] ?? '[]'), true);
            if (!is_array($events) || !in_array($event, $events, true)) {
                continue;
            }
            $matched[] = $row;
        }

        return $matched;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM webhooks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @param list<string> $events
     */
    public function create(string $url, string $secret, array $events, string $description = ''): int
    {
        $events = array_values(array_filter($events, static fn (string $e): bool => WebhookEvent::isValid($e)));
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO webhooks (url, secret, events, description, enabled, created_at)
             VALUES (:url, :secret, :events, :description, 1, :created_at)'
        );
        $stmt->execute([
            'url' => $url,
            'secret' => $secret,
            'events' => json_encode($events, JSON_THROW_ON_ERROR),
            'description' => trim($description),
            'created_at' => gmdate('c'),
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM webhooks WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE webhooks SET enabled = :enabled WHERE id = :id');
        $stmt->execute(['enabled' => $enabled ? 1 : 0, 'id' => $id]);
    }

    public function recordDelivery(
        int $webhookId,
        string $event,
        string $payloadJson,
        ?int $responseCode,
        ?string $error,
        int $durationMs,
    ): void {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO webhook_deliveries
                (webhook_id, event, payload_json, response_code, error, delivered_at, duration_ms)
             VALUES (:webhook_id, :event, :payload_json, :response_code, :error, :delivered_at, :duration_ms)'
        )->execute([
            'webhook_id' => $webhookId,
            'event' => $event,
            'payload_json' => $payloadJson,
            'response_code' => $responseCode,
            'error' => $error,
            'delivered_at' => gmdate('c'),
            'duration_ms' => $durationMs,
        ]);

        $pdo->prepare(
            'UPDATE webhooks SET last_delivery_at = :at, last_status = :status WHERE id = :id'
        )->execute([
            'at' => gmdate('c'),
            'status' => $responseCode,
            'id' => $webhookId,
        ]);

        $pdo->prepare(
            'DELETE FROM webhook_deliveries
             WHERE webhook_id = :id
               AND id NOT IN (
                   SELECT id FROM webhook_deliveries
                   WHERE webhook_id = :id
                   ORDER BY delivered_at DESC, id DESC
                   LIMIT 50
               )'
        )->execute(['id' => $webhookId]);
    }
}
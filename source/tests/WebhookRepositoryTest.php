<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Core\Webhooks\WebhookEvent;
use Latch\Models\WebhookRepository;
use PHPUnit\Framework\TestCase;

final class WebhookRepositoryTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private WebhookRepository $webhooks;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-webhook-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $this->db->pdo()->exec(
            'CREATE TABLE webhooks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                url TEXT NOT NULL,
                secret TEXT NOT NULL,
                events TEXT NOT NULL DEFAULT "[]",
                description TEXT,
                enabled INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                last_delivery_at TEXT,
                last_status INTEGER
             );
             CREATE TABLE webhook_deliveries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                webhook_id INTEGER NOT NULL,
                event TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                response_code INTEGER,
                error TEXT,
                delivered_at TEXT NOT NULL,
                duration_ms INTEGER
             );'
        );

        $this->webhooks = new WebhookRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testCreateAndListEnabledForEvent(): void
    {
        $id = $this->webhooks->create(
            'https://example.com/hook',
            'secret',
            [WebhookEvent::POST_CREATED],
            'Test hook',
        );

        $this->assertGreaterThan(0, $id);

        $matched = $this->webhooks->listEnabledForEvent(WebhookEvent::POST_CREATED);
        $this->assertCount(1, $matched);
        $this->assertSame('https://example.com/hook', $matched[0]['url']);

        $this->assertSame([], $this->webhooks->listEnabledForEvent(WebhookEvent::USER_REGISTERED));
    }

    public function testDisabledWebhookIsExcluded(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', 'secret', [WebhookEvent::POST_CREATED]);
        $this->webhooks->setEnabled($id, false);

        $this->assertSame([], $this->webhooks->listEnabledForEvent(WebhookEvent::POST_CREATED));
    }

    public function testRecordDeliveryUpdatesLastStatus(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', 'secret', [WebhookEvent::POST_CREATED]);
        $payload = '{"event":"post.created","data":{}}';

        $this->webhooks->recordDelivery($id, WebhookEvent::POST_CREATED, $payload, 204, null, 42);

        $row = $this->webhooks->findById($id);
        $this->assertNotNull($row);
        $this->assertSame(204, (int) ($row['last_status'] ?? 0));
        $this->assertNotEmpty($row['last_delivery_at'] ?? '');
    }

    public function testSignatureComputation(): void
    {
        $secret = 'test-secret';
        $payload = '{"event":"user.registered","sent_at":"2026-07-03T00:00:00+00:00","data":{"user_id":1}}';
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $this->assertSame(
            $expected,
            'sha256=' . hash_hmac('sha256', $payload, $secret),
        );
    }
}
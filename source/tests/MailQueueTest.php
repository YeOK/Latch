<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\CronService;
use Latch\Core\Database;
use Latch\Core\EmailNotificationService;
use Latch\Core\MailQueueService;
use Latch\Core\OutboundMailer;
use Latch\Core\RateLimiter;
use Latch\Models\ApiAuditLogRepository;
use Latch\Models\EmailChangeRepository;
use Latch\Models\EmailVerificationRepository;
use Latch\Models\MailQueueRepository;
use Latch\Models\NotificationRepository;
use Latch\Models\OAuthTokenRepository;
use Latch\Models\PasswordResetRepository;
use Latch\Models\SettingRepository;
use Latch\Models\UserRepository;
use Latch\Models\UserSessionRepository;
use PHPUnit\Framework\TestCase;

final class MailQueueTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private SettingRepository $settings;
    private MailQueueRepository $queue;
    private FakeOutboundMailer $mail;
    private MailQueueService $mailQueue;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-mail-queue-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            "CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
             CREATE TABLE mail_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                recipient TEXT NOT NULL,
                subject TEXT NOT NULL,
                body TEXT NOT NULL,
                queued_at TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                last_error TEXT,
                sent_at TEXT
             );
             CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                email TEXT,
                notify_email INTEGER NOT NULL DEFAULT 1
             );
             INSERT INTO users (id, username, email) VALUES (2, 'member', 'member@example.com');
             CREATE TABLE search_attempts (id INTEGER PRIMARY KEY, ip_address TEXT, searched_at TEXT);
             CREATE TABLE api_rate_attempts (id INTEGER PRIMARY KEY, bucket_key TEXT, requested_at TEXT);"
        );

        $this->settings = new SettingRepository($this->db);
        $this->settings->setBool('mail_enabled', true);
        $this->settings->set('mail_from_email', 'noreply@test');
        $this->settings->set('mail_from_name', 'Test');
        $this->settings->setBool('mail_queue_enabled', true);
        $this->settings->setBool('email_notify_warnings', true);

        $this->queue = new MailQueueRepository($this->db);
        $this->mail = new FakeOutboundMailer();
        $this->mailQueue = new MailQueueService($this->mail, $this->settings, $this->queue);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testEnqueueAndProcessBatch(): void
    {
        $this->assertTrue($this->mailQueue->enqueue('one@example.com', 'Subject A', 'Body A'));
        $this->assertTrue($this->mailQueue->enqueue('two@example.com', 'Subject B', 'Body B'));
        $this->assertSame(2, $this->mailQueue->pendingCount());

        $stats = $this->mailQueue->processBatch();

        $this->assertSame(['sent' => 2, 'failed' => 0], $stats);
        $this->assertSame(0, $this->mailQueue->pendingCount());
        $this->assertCount(2, $this->mail->sent);
        $this->assertSame('one@example.com', $this->mail->sent[0]['to']);
        $this->assertSame('two@example.com', $this->mail->sent[1]['to']);
    }

    public function testFailedSendIncrementsAttempts(): void
    {
        $this->mail->shouldFail = true;
        $this->mailQueue->enqueue('fail@example.com', 'Subject', 'Body');

        $stats = $this->mailQueue->processBatch();

        $this->assertSame(['sent' => 0, 'failed' => 1], $stats);
        $this->assertSame(1, $this->mailQueue->pendingCount());

        $row = $this->db->pdo()->query('SELECT attempts, last_error FROM mail_queue WHERE id = 1')->fetch();
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertSame('simulated failure', (string) $row['last_error']);
    }

    public function testEmailNotificationUsesQueueWhenEnabled(): void
    {
        $users = new UserRepository($this->db);
        $service = new EmailNotificationService(
            $this->mail,
            $this->settings,
            $users,
            $this->mailQueue,
        );

        $service->maybeSend(2, NotificationRepository::TYPE_USER_WARN, 'Warning body', '/topic/1');

        $this->assertCount(0, $this->mail->sent);
        $this->assertSame(1, $this->mailQueue->pendingCount());

        $row = $this->db->pdo()->query('SELECT recipient, subject, body FROM mail_queue LIMIT 1')->fetch();
        $this->assertSame('member@example.com', $row['recipient']);
        $this->assertStringContainsString('Staff warning', (string) $row['subject']);
        $this->assertStringContainsString('Warning body', (string) $row['body']);
    }

    public function testEmailNotificationFallsBackToSyncWhenQueueDisabled(): void
    {
        $this->settings->setBool('mail_queue_enabled', false);
        $users = new UserRepository($this->db);
        $service = new EmailNotificationService(
            $this->mail,
            $this->settings,
            $users,
            $this->mailQueue,
        );

        $service->maybeSend(2, NotificationRepository::TYPE_USER_WARN, 'Warning body', '/topic/1');

        $this->assertCount(1, $this->mail->sent);
        $this->assertSame(0, $this->mailQueue->pendingCount());
    }

    public function testHourlyCronDrainsMailQueue(): void
    {
        $this->mailQueue->enqueue('cron@example.com', 'Cron subject', 'Cron body');
        $cron = $this->buildCron();

        $stats = $cron->runHourly();

        $this->assertSame(1, $stats['mail_queue_sent']);
        $this->assertSame(0, $stats['mail_queue_failed']);
        $this->assertSame(0, $this->mailQueue->pendingCount());
        $this->assertCount(1, $this->mail->sent);
    }

    private function buildCron(): CronService
    {
        return new CronService(
            $this->db,
            $this->settings,
            new PasswordResetRepository($this->db),
            new EmailVerificationRepository($this->db),
            new EmailChangeRepository($this->db),
            new UserSessionRepository($this->db),
            new UserRepository($this->db),
            new NotificationRepository($this->db),
            new RateLimiter($this->db),
            new OAuthTokenRepository($this->db),
            new ApiAuditLogRepository($this->db),
            null,
            $this->mailQueue,
        );
    }
}

/**
 * @internal
 */
final class FakeOutboundMailer implements OutboundMailer
{
    public bool $shouldFail = false;

    /** @var list<array{to: string, subject: string, body: string}> */
    public array $sent = [];

    public function send(string $to, string $subject, string $body): bool
    {
        if ($this->shouldFail) {
            return false;
        }

        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];

        return true;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function lastError(): ?string
    {
        return $this->shouldFail ? 'simulated failure' : null;
    }

    public function siteUrl(): string
    {
        return 'http://localhost';
    }
}
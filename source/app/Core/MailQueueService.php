<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use Latch\Models\MailQueueRepository;
use Latch\Models\SettingRepository;

/**
 * Optional queue for outbound notification emails. Auth flows (verify, reset) stay synchronous.
 */
final class MailQueueService
{
    public function __construct(
        private readonly OutboundMailer $mail,
        private readonly SettingRepository $settings,
        private readonly MailQueueRepository $queue,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->getBool('mail_queue_enabled')
            && $this->queue->tableExists()
            && $this->mail->isEnabled()
            && $this->mail->isConfigured();
    }

    public function enqueue(string $recipient, string $subject, string $body): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $this->queue->enqueue($recipient, $subject, $body);

        return true;
    }

    /**
     * @return array{sent: int, failed: int}
     */
    public function processBatch(): array
    {
        if (!$this->queue->tableExists() || !$this->mail->isEnabled() || !$this->mail->isConfigured()) {
            return ['sent' => 0, 'failed' => 0];
        }

        $batchSize = max(1, (int) ($this->settings->get('mail_queue_batch_size', '50') ?? '50'));
        $maxAttempts = max(1, (int) ($this->settings->get('mail_queue_max_attempts', '5') ?? '5'));
        $rows = $this->queue->fetchPending($batchSize, $maxAttempts);

        $sent = 0;
        $failed = 0;

        foreach ($rows as $row) {
            if ($this->mail->send($row['recipient'], $row['subject'], $row['body'])) {
                $this->queue->markSent($row['id']);
                $sent++;
                continue;
            }

            $this->queue->recordFailure($row['id'], $this->mail->lastError() ?? 'send failed');
            $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    public function pendingCount(): int
    {
        return $this->queue->pendingCount();
    }

    public function pruneCompleted(): int
    {
        $maxAttempts = max(1, (int) ($this->settings->get('mail_queue_max_attempts', '5') ?? '5'));

        return $this->queue->pruneSent(7) + $this->queue->pruneDead($maxAttempts, 30);
    }
}
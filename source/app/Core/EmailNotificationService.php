<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use Latch\Models\NotificationRepository;
use Latch\Models\SettingRepository;
use Latch\Models\UserRepository;

/**
 * Optional email copies of in-app notifications (site + user toggles).
 */
final class EmailNotificationService
{
    public function __construct(
        private readonly OutboundMailer $mail,
        private readonly SettingRepository $settings,
        private readonly UserRepository $users,
        private readonly ?MailQueueService $mailQueue = null,
    ) {
    }

    public function maybeSend(
        int $userId,
        string $eventType,
        string $message,
        string $url,
    ): void {
        if (!$this->settings->getBool('mail_enabled') || !$this->mail->isConfigured()) {
            return;
        }

        if (!$this->eventTypeEnabled($eventType)) {
            return;
        }

        $user = $this->users->findById($userId);
        if ($user === null || !$this->users->wantsEmailNotifications($user)) {
            return;
        }

        $email = (string) ($user['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $siteName = $this->settings->get('site_name', (string) $this->mail->siteUrl());
        $fullUrl = rtrim($this->mail->siteUrl(), '/') . $url;
        $subject = $siteName . ' — ' . $this->subjectFor($eventType);
        $body = $message . "\n\n" . $fullUrl . "\n\n—\n"
            . "You received this because email notifications are enabled on your account.\n"
            . 'Manage preferences: ' . rtrim($this->mail->siteUrl(), '/') . '/profile';

        if ($this->mailQueue !== null && $this->mailQueue->enqueue($email, $subject, $body)) {
            return;
        }

        $this->mail->send($email, $subject, $body);
    }

    private function eventTypeEnabled(string $eventType): bool
    {
        return match ($eventType) {
            NotificationRepository::TYPE_TOPIC_REPLY,
            NotificationRepository::TYPE_POST_QUOTE => $this->settings->getBool('email_notify_replies', true),
            NotificationRepository::TYPE_MENTION => $this->settings->getBool('email_notify_mentions', true),
            NotificationRepository::TYPE_POST_LIKE => $this->settings->getBool('email_notify_likes'),
            NotificationRepository::TYPE_USER_WARN => $this->settings->getBool('email_notify_warnings', true),
            NotificationRepository::TYPE_DIRECT_MESSAGE => $this->settings->getBool('email_notify_messages', true),
            NotificationRepository::TYPE_STAFF_ACTION,
            NotificationRepository::TYPE_POST_PENDING => $this->settings->getBool('email_notify_staff', true),
            default => false,
        };
    }

    private function subjectFor(string $eventType): string
    {
        return match ($eventType) {
            NotificationRepository::TYPE_TOPIC_REPLY => 'New reply',
            NotificationRepository::TYPE_POST_QUOTE => 'You were quoted',
            NotificationRepository::TYPE_MENTION => 'You were mentioned',
            NotificationRepository::TYPE_POST_LIKE => 'Your post was liked',
            NotificationRepository::TYPE_USER_WARN => 'Staff warning',
            NotificationRepository::TYPE_DIRECT_MESSAGE => 'New message',
            NotificationRepository::TYPE_STAFF_ACTION => 'Staff action on your content',
            NotificationRepository::TYPE_POST_PENDING => 'Post awaiting approval',
            default => 'Notification',
        };
    }
}
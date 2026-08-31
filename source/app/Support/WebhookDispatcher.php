<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

use Latch\Core\Plugins\PostSaveContext;
use Latch\Core\Webhooks\WebhookEvent;
use Latch\Models\WebhookRepository;

/**
 * Delivers signed JSON webhook payloads for forum events.
 */
final class WebhookDispatcher
{
    private const TIMEOUT_SECONDS = 5;

    public function __construct(private readonly WebhookRepository $webhooks)
    {
    }

    public function postCreated(PostSaveContext $context): void
    {
        $post = $context->post;
        $topic = $context->topic;
        if ($post === null || $topic === null) {
            return;
        }

        $this->dispatch(WebhookEvent::POST_CREATED, [
            'post_id' => (int) ($post['id'] ?? 0),
            'topic_id' => (int) ($topic['id'] ?? 0),
            'board_id' => (int) ($context->board['id'] ?? 0),
            'board_slug' => (string) ($context->board['slug'] ?? ''),
            'author_id' => (int) ($context->user['id'] ?? 0),
            'author' => (string) ($context->user['username'] ?? ''),
            'kind' => $context->kind,
            'is_first_post' => $context->kind === 'topic',
        ]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function userRegistered(array $user): void
    {
        $this->dispatch(WebhookEvent::USER_REGISTERED, [
            'user_id' => (int) ($user['id'] ?? 0),
            'username' => (string) ($user['username'] ?? ''),
            'role' => (string) ($user['role'] ?? 'member'),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function dispatch(string $event, array $data): void
    {
        $targets = $this->webhooks->listEnabledForEvent($event);
        if ($targets === []) {
            return;
        }

        $payload = json_encode([
            'event' => $event,
            'sent_at' => gmdate('c'),
            'data' => $data,
        ], JSON_THROW_ON_ERROR);

        foreach ($targets as $webhook) {
            $this->deliver($webhook, $event, $payload);
        }
    }

    /**
     * @param array<string, mixed> $webhook
     */
    private function deliver(array $webhook, string $event, string $payload): void
    {
        $id = (int) $webhook['id'];
        $url = (string) $webhook['url'];
        $secret = (string) $webhook['secret'];
        $ssrfError = OutboundUrlGuard::publicHttpsUrlError($url);
        if ($ssrfError !== null) {
            $this->webhooks->recordDelivery(
                $id,
                $event,
                $payload,
                null,
                $ssrfError,
                0,
            );

            return;
        }

        $signature = hash_hmac('sha256', $payload, $secret);
        $started = hrtime(true);

        $fetched = OutboundUrlGuard::request(
            'POST',
            $url,
            [
                'Content-Type: application/json',
                'User-Agent: Latch-Webhooks/1.0',
                'X-Latch-Event: ' . $event,
                'X-Latch-Signature: sha256=' . $signature,
            ],
            $payload,
            self::TIMEOUT_SECONDS,
            1_048_576,
        );

        $error = null;
        $responseCode = null;
        if ($fetched === null) {
            $error = 'Request failed';
        } else {
            $responseCode = $fetched['status'];
        }

        if ($responseCode !== null && ($responseCode < 200 || $responseCode >= 300)) {
            $error = 'HTTP ' . $responseCode;
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        $this->webhooks->recordDelivery(
            $id,
            $event,
            $payload,
            $responseCode,
            $error,
            $durationMs,
        );
    }
}
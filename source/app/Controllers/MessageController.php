<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\DateTimeFormatter;
use Latch\Core\Response;
use Latch\Models\DirectMessageRepository;

final class MessageController
{
    private const CONVERSATION_LIMIT = 50;
    private const MESSAGE_LIMIT = 50;

    private readonly DateTimeFormatter $dates;

    public function __construct(private readonly Application $app)
    {
        $this->dates = new DateTimeFormatter();
    }

    public function index(array $params = []): void
    {
        $user = $this->requireUser();
        $activeId = max(0, (int) ($params['id'] ?? $this->app->request()->input('c', 0)));

        $conversation = null;
        if ($activeId > 0) {
            $conversation = $this->app->directMessages()->getConversationForUser($activeId, (int) $user['id']);
            if ($conversation === null) {
                $this->app->session()->flash('error', 'Conversation not found.');
                Response::redirect('/messages');
            }
            $this->app->directMessages()->markRead($activeId, (int) $user['id']);
        }

        $this->app->render('messages/index.html.twig', [
            'active_conversation_id' => $activeId > 0 ? $activeId : null,
            'active_conversation' => $conversation,
            'messages_unread' => $this->app->directMessages()->countUnreadForUser((int) $user['id']),
        ]);
    }

    public function feed(array $params = []): void
    {
        $user = $this->requireUserJson();
        $userId = (int) $user['id'];
        $limit = max(1, min(self::CONVERSATION_LIMIT, (int) $this->app->request()->input('limit', self::CONVERSATION_LIMIT)));

        $items = $this->app->directMessages()->listConversationsForUser($userId, $limit, 0);

        Response::json([
            'ok' => true,
            'conversations' => array_map(fn (array $item): array => $this->serializeConversation($item, $user), $items),
            'unread_count' => $this->app->directMessages()->countUnreadForUser($userId),
        ]);
    }

    public function threadFeed(array $params): void
    {
        $user = $this->requireUserJson();
        $userId = (int) $user['id'];
        $conversationId = (int) ($params['id'] ?? 0);

        $conversation = $this->app->directMessages()->getConversationForUser($conversationId, $userId);
        if ($conversation === null) {
            Response::json(['ok' => false, 'message' => 'Conversation not found.'], 404);
        }

        $afterId = (int) $this->app->request()->input('after', 0);
        $messages = $this->app->directMessages()->listMessages(
            $conversationId,
            $userId,
            self::MESSAGE_LIMIT,
            $afterId > 0 ? $afterId : null,
        );

        if ($afterId <= 0) {
            $this->app->directMessages()->markRead($conversationId, $userId);
        }

        Response::json([
            'ok' => true,
            'conversation' => $this->serializeConversation($conversation, $user),
            'messages' => array_map(fn (array $item): array => $this->serializeMessage($item, $user), $messages),
            'unread_count' => $this->app->directMessages()->countUnreadForUser($userId),
        ]);
    }

    public function deleteConversation(array $params): void
    {
        $user = $this->requireUserJson();
        $this->validateCsrf();

        $conversationId = (int) ($params['id'] ?? 0);
        if (!$this->app->directMessages()->deleteConversationIfEmpty($conversationId, (int) $user['id'])) {
            Response::json([
                'ok' => false,
                'message' => 'Delete each message first, or the conversation was not found.',
            ], 403);
        }

        Response::json([
            'ok' => true,
            'conversation_id' => $conversationId,
            'unread_count' => $this->app->directMessages()->countUnreadForUser((int) $user['id']),
        ]);
    }

    public function start(array $params = []): void
    {
        $user = $this->requireUserJson();
        $this->validateCsrf();

        $username = trim((string) $this->app->request()->input('username', ''));
        if ($username === '') {
            Response::json(['ok' => false, 'message' => 'Username is required.'], 400);
        }

        $recipient = $this->app->users()->findByUsername($username);
        if ($recipient === null || $this->app->users()->isAnonymised($recipient)) {
            Response::json(['ok' => false, 'message' => 'User not found.'], 404);
        }

        if (!$this->app->messages()->canStartWith($user, (int) $recipient['id'])) {
            Response::json(['ok' => false, 'message' => 'You cannot message that user.'], 403);
        }

        $conversationId = $this->app->directMessages()->findOrCreateConversation(
            (int) $user['id'],
            (int) $recipient['id'],
        );

        $conversation = $this->app->directMessages()->getConversationForUser($conversationId, (int) $user['id']);

        Response::json([
            'ok' => true,
            'conversation_id' => $conversationId,
            'conversation' => $conversation !== null ? $this->serializeConversation($conversation, $user) : null,
        ]);
    }

    public function send(array $params): void
    {
        $user = $this->requireUserJson();
        $this->validateCsrf();

        $conversationId = (int) ($params['id'] ?? 0);
        if (!$this->app->directMessages()->isParticipant($conversationId, (int) $user['id'])) {
            Response::json(['ok' => false, 'message' => 'Conversation not found.'], 404);
        }

        $body = (string) $this->app->request()->input('body', '');
        $conversation = $this->app->directMessages()->getConversationForUser($conversationId, (int) $user['id']);
        if ($conversation === null) {
            Response::json(['ok' => false, 'message' => 'Conversation not found.'], 404);
        }

        $recipientId = (int) ($conversation['other_user']['id'] ?? 0);
        $result = $this->app->messages()->sendToUser($user, $recipientId, $body);
        if (!$result['ok']) {
            Response::json(['ok' => false, 'message' => $result['message']], 400);
        }

        $messageId = (int) ($result['message_id'] ?? 0);
        $messages = $messageId > 0
            ? $this->app->directMessages()->listMessages($conversationId, (int) $user['id'], 1, $messageId - 1)
            : [];

        Response::json([
            'ok' => true,
            'conversation_id' => $conversationId,
            'message' => isset($messages[0]) ? $this->serializeMessage($messages[0], $user) : null,
            'unread_count' => $this->app->directMessages()->countUnreadForUser((int) $user['id']),
        ]);
    }

    public function deleteMessage(array $params): void
    {
        $user = $this->requireUserJson();
        $this->validateCsrf();

        $conversationId = (int) ($params['id'] ?? 0);
        $messageId = (int) $this->app->request()->input('message_id', 0);
        if ($messageId <= 0 || !$this->app->directMessages()->isParticipant($conversationId, (int) $user['id'])) {
            Response::json(['ok' => false, 'message' => 'Message not found.'], 404);
        }

        $isStaff = in_array((string) ($user['role'] ?? ''), ['admin', 'mod'], true);
        if (!$this->app->directMessages()->softDeleteMessage($messageId, (int) $user['id'], $isStaff)) {
            Response::json(['ok' => false, 'message' => 'Could not delete message.'], 403);
        }

        Response::json([
            'ok' => true,
            'message_id' => $messageId,
            'unread_count' => $this->app->directMessages()->countUnreadForUser((int) $user['id']),
        ]);
    }

    public function markRead(array $params): void
    {
        $user = $this->requireUserJson();
        $this->validateCsrf();

        $conversationId = (int) ($params['id'] ?? 0);
        if (!$this->app->directMessages()->isParticipant($conversationId, (int) $user['id'])) {
            Response::json(['ok' => false, 'message' => 'Conversation not found.'], 404);
        }

        $this->app->directMessages()->markRead($conversationId, (int) $user['id']);

        Response::json([
            'ok' => true,
            'unread_count' => $this->app->directMessages()->countUnreadForUser((int) $user['id']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireUser(): array
    {
        $this->app->auth()->requireLogin();
        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireUserJson(): array
    {
        $this->app->auth()->requireLogin();
        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::json(['ok' => false, 'message' => 'Sign in required.'], 401);
        }

        return $user;
    }

    private function validateCsrf(): void
    {
        if ($this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            return;
        }

        Response::json(['ok' => false, 'message' => 'Invalid form token.'], 403);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed>|null $viewer
     */
    private function serializeConversation(array $item, ?array $viewer = null): array
    {
        $last = $item['last_message'] ?? null;
        if (is_array($last)) {
            $last['created_at_label'] = $this->dates->format((string) ($last['created_at'] ?? ''));
        }

        $conversationId = (int) $item['id'];
        $canDeleteConversation = $viewer !== null
            && $this->app->directMessages()->isParticipant($conversationId, (int) $viewer['id'])
            && $this->app->directMessages()->countActiveMessages($conversationId) === 0;

        return [
            'id' => $conversationId,
            'updated_at' => (string) ($item['updated_at'] ?? ''),
            'other_user' => $item['other_user'] ?? [],
            'last_message' => $last,
            'unread' => !empty($item['unread']),
            'can_delete_conversation' => $canDeleteConversation,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed>|null $viewer
     * @return array<string, mixed>
     */
    private function serializeMessage(array $item, ?array $viewer = null): array
    {
        $kind = (string) ($item['kind'] ?? DirectMessageRepository::KIND_USER);
        $isStaff = $viewer !== null
            && in_array((string) ($viewer['role'] ?? ''), ['admin', 'mod'], true);
        $canDelete = $isStaff
            || (!empty($item['is_mine']) && $kind === DirectMessageRepository::KIND_USER);

        return [
            'id' => (int) $item['id'],
            'body' => (string) ($item['body'] ?? ''),
            'body_html' => $this->app->postFormatter()->format((string) ($item['body'] ?? '')),
            'kind' => $kind,
            'is_warning' => $kind === DirectMessageRepository::KIND_STAFF_WARNING,
            'created_at' => (string) ($item['created_at'] ?? ''),
            'created_at_label' => $this->dates->format((string) ($item['created_at'] ?? '')),
            'sender' => $item['sender'] ?? [],
            'is_mine' => !empty($item['is_mine']),
            'can_delete' => $canDelete,
        ];
    }
}
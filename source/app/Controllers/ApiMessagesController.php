<?php

declare(strict_types=1);

namespace Latch\Controllers;

use Latch\Core\ApiContext;
use Latch\Core\ApiResponse;
use Latch\Core\ApiSerializer;
use Latch\Core\Application;
use Latch\Core\OAuthScopes;
final class ApiMessagesController
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 100;

    private readonly ApiSerializer $serializer;

    public function __construct(private readonly Application $app)
    {
        $this->serializer = new ApiSerializer();
    }

    public function index(array $params = []): void
    {
        $ctx = $this->begin('GET', '/api/v1/messages', OAuthScopes::MESSAGES_READ);
        $userId = (int) $ctx->userId();
        $limit = $this->limit();

        $items = $this->app->directMessages()->listConversationsForUser($userId, $limit, 0);
        $data = array_map(
            fn (array $item): array => $this->serializer->conversation($item),
            $items,
        );

        ApiResponse::data($data, 200, [
            'count' => count($data),
            'unread_count' => $this->app->directMessages()->countUnreadForUser($userId),
        ]);
    }

    public function show(array $params): void
    {
        $conversationId = (int) ($params['id'] ?? 0);
        $ctx = $this->begin('GET', '/api/v1/messages/' . $conversationId, OAuthScopes::MESSAGES_READ);
        $userId = (int) $ctx->userId();

        $conversation = $this->app->directMessages()->getConversationForUser($conversationId, $userId);
        if ($conversation === null) {
            ApiResponse::error('not_found', 'Conversation not found.', 404);
        }

        $afterId = (int) $this->app->request()->input('after', 0);
        $limit = $this->limit();
        $messages = $this->app->directMessages()->listMessages(
            $conversationId,
            $userId,
            $limit,
            $afterId > 0 ? $afterId : null,
        );

        if ($afterId <= 0) {
            $this->app->directMessages()->markRead($conversationId, $userId);
        }

        ApiResponse::data([
            'conversation' => $this->serializer->conversation($conversation),
            'messages' => array_map(
                fn (array $item): array => $this->serializer->message(
                    $item,
                    $userId,
                    $this->app->postFormatter(),
                ),
                $messages,
            ),
        ], 200, [
            'unread_count' => $this->app->directMessages()->countUnreadForUser($userId),
        ]);
    }

    public function start(array $params = []): void
    {
        $ctx = $this->begin('POST', '/api/v1/messages', OAuthScopes::MESSAGES_WRITE);
        $user = $ctx->user ?? [];
        $userId = (int) $ctx->userId();

        $username = trim((string) $this->app->request()->jsonField('username', ''));
        if ($username === '') {
            ApiResponse::error('invalid_request', 'username is required.', 400);
        }

        $recipient = $this->app->users()->findByUsername($username);
        if ($recipient === null || $this->app->users()->isAnonymised($recipient)) {
            ApiResponse::error('not_found', 'User not found.', 404);
        }

        if (!$this->app->messages()->canStartWith($user, (int) $recipient['id'])) {
            ApiResponse::error('forbidden', 'You cannot message that user.', 403);
        }

        $conversationId = $this->app->directMessages()->findOrCreateConversation(
            $userId,
            (int) $recipient['id'],
        );

        $conversation = $this->app->directMessages()->getConversationForUser($conversationId, $userId);
        $body = trim((string) $this->app->request()->jsonField('body', ''));
        $message = null;

        if ($body !== '') {
            $result = $this->app->messages()->sendToUser($user, (int) $recipient['id'], $body);
            if (!$result['ok']) {
                ApiResponse::error('invalid_request', (string) $result['message'], 400);
            }

            $messageId = (int) ($result['message_id'] ?? 0);
            if ($messageId > 0) {
                $rows = $this->app->directMessages()->listMessages($conversationId, $userId, 1, $messageId - 1);
                $message = isset($rows[0])
                    ? $this->serializer->message($rows[0], $userId, $this->app->postFormatter())
                    : null;
            }
            $conversation = $this->app->directMessages()->getConversationForUser($conversationId, $userId) ?? $conversation;
        }

        ApiResponse::data([
            'conversation_id' => $conversationId,
            'conversation' => $conversation !== null ? $this->serializer->conversation($conversation) : null,
            'message' => $message,
        ], $body !== '' ? 201 : 200);
    }

    public function send(array $params): void
    {
        $conversationId = (int) ($params['id'] ?? 0);
        $ctx = $this->begin('POST', '/api/v1/messages/' . $conversationId . '/send', OAuthScopes::MESSAGES_WRITE);
        $user = $ctx->user ?? [];
        $userId = (int) $ctx->userId();

        if (!$this->app->directMessages()->isParticipant($conversationId, $userId)) {
            ApiResponse::error('not_found', 'Conversation not found.', 404);
        }

        $conversation = $this->app->directMessages()->getConversationForUser($conversationId, $userId);
        if ($conversation === null) {
            ApiResponse::error('not_found', 'Conversation not found.', 404);
        }

        $body = trim((string) $this->app->request()->jsonField('body', ''));
        if ($body === '') {
            ApiResponse::error('invalid_request', 'body is required.', 400);
        }

        $recipientId = (int) ($conversation['other_user']['id'] ?? 0);
        $result = $this->app->messages()->sendToUser($user, $recipientId, $body);
        if (!$result['ok']) {
            ApiResponse::error('invalid_request', (string) $result['message'], 400);
        }

        $messageId = (int) ($result['message_id'] ?? 0);
        $message = null;
        if ($messageId > 0) {
            $rows = $this->app->directMessages()->listMessages($conversationId, $userId, 1, $messageId - 1);
            $message = isset($rows[0])
                ? $this->serializer->message($rows[0], $userId, $this->app->postFormatter())
                : null;
        }

        ApiResponse::data([
            'conversation_id' => $conversationId,
            'message' => $message,
        ], 201, [
            'unread_count' => $this->app->directMessages()->countUnreadForUser($userId),
        ]);
    }

    public function markRead(array $params): void
    {
        $conversationId = (int) ($params['id'] ?? 0);
        $ctx = $this->begin('POST', '/api/v1/messages/' . $conversationId . '/read', OAuthScopes::MESSAGES_READ);
        $userId = (int) $ctx->userId();

        if (!$this->app->directMessages()->isParticipant($conversationId, $userId)) {
            ApiResponse::error('not_found', 'Conversation not found.', 404);
        }

        $this->app->directMessages()->markRead($conversationId, $userId);

        ApiResponse::data([
            'conversation_id' => $conversationId,
            'read' => true,
        ], 200, [
            'unread_count' => $this->app->directMessages()->countUnreadForUser($userId),
        ]);
    }

    private function begin(string $method, string $path, string $requiredScope): ApiContext
    {
        $ctx = $this->app->apiAuth()->resolve();

        if ($ctx->clientId === null) {
            ApiResponse::error('unauthorized', 'Bearer token required.', 401);
        }

        if (!$ctx->isLoggedIn()) {
            ApiResponse::error('unauthorized', 'User-delegated token required.', 401);
        }

        if (!$ctx->hasScope($requiredScope)) {
            ApiResponse::error('insufficient_scope', "The {$requiredScope} scope is required.", 403);
        }

        if (!$this->app->directMessages()->isAvailable()) {
            ApiResponse::error('unavailable', 'Direct messages are not available.', 503);
        }

        register_shutdown_function(function () use ($method, $path, $ctx): void {
            $status = http_response_code();
            if (!is_int($status) || $status === false) {
                $status = 200;
            }
            $this->app->apiAuditLog()->record(
                $ctx->clientId,
                $ctx->userId(),
                $method,
                $path,
                $status,
                $this->app->request()->ip(),
            );
        });

        return $ctx;
    }

    private function limit(): int
    {
        $limit = (int) $this->app->request()->input('limit', self::DEFAULT_LIMIT);

        return max(1, min(self::MAX_LIMIT, $limit));
    }
}
<?php

declare(strict_types=1);

namespace Latch\Core;

use Latch\Models\DirectMessageRepository;
use Latch\Models\UserBlockRepository;
use Latch\Models\UserRepository;

/**
 * Direct message delivery with opt-in, staff bypass, and warning threads.
 */
final class MessageService
{
    private const SEND_LIMIT_PER_HOUR = 60;

    public function __construct(
        private readonly DirectMessageRepository $messages,
        private readonly UserBlockRepository $blocks,
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
        private readonly SpamGuard $spamGuard,
    ) {
    }

    /**
     * @param array<string, mixed> $sender
     * @return array{ok: bool, message: string, conversation_id?: int, message_id?: int}
     */
    public function sendToUser(array $sender, int $recipientId, string $body): array
    {
        if (!$this->messages->isAvailable()) {
            return ['ok' => false, 'message' => 'Direct messages are not available yet.'];
        }

        $senderId = (int) $sender['id'];
        $error = $this->sendError($sender, $recipientId);
        if ($error !== null) {
            return ['ok' => false, 'message' => $error];
        }

        $bodyError = $this->validateBody($body, $sender);
        if ($bodyError !== null) {
            return ['ok' => false, 'message' => $bodyError];
        }

        if ($this->messages->countRecentSends($senderId, 60) >= self::SEND_LIMIT_PER_HOUR) {
            return ['ok' => false, 'message' => 'Too many messages sent. Wait a while and try again.'];
        }

        $conversationId = $this->messages->findOrCreateConversation($senderId, $recipientId);
        $messageId = $this->messages->addMessage($conversationId, $senderId, trim($body));

        $this->notifyRecipient(
            $recipientId,
            $sender,
            $conversationId,
            DirectMessageRepository::KIND_USER,
            'sent you a message',
        );

        return [
            'ok' => true,
            'message' => 'Message sent.',
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
        ];
    }

    /**
     * @param array<string, mixed> $staff
     * @return array{ok: bool, message: string, conversation_id?: int, message_id?: int}
     */
    public function deliverStaffWarning(int $recipientId, array $staff, string $reason, ?int $reportId = null): array
    {
        if (!$this->messages->isAvailable()) {
            return ['ok' => false, 'message' => 'Direct messages are not available yet.'];
        }

        if (!$this->isStaff($staff)) {
            return ['ok' => false, 'message' => 'Staff access required.'];
        }

        $recipient = $this->users->findById($recipientId);
        if ($recipient === null || $this->users->isAnonymised($recipient)) {
            return ['ok' => false, 'message' => 'Recipient not found.'];
        }

        $staffId = (int) $staff['id'];
        $body = "**Staff warning**\n\n"
            . 'Reason: ' . trim($reason) . "\n\n"
            . 'This warning was issued following a moderation review. '
            . 'Further violations may result in additional enforcement.';

        $conversationId = $this->messages->findOrCreateConversation($staffId, $recipientId);
        $messageId = $this->messages->addMessage(
            $conversationId,
            $staffId,
            $body,
            DirectMessageRepository::KIND_STAFF_WARNING,
        );

        $this->notifications->onUserWarned(
            $recipientId,
            $staff,
            $reason,
            $reportId,
            $conversationId,
        );

        return [
            'ok' => true,
            'message' => 'Warning delivered.',
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
        ];
    }

    public function canStartWith(array $sender, int $recipientId): bool
    {
        if (!$this->messages->isAvailable()) {
            return false;
        }

        return $this->sendError($sender, $recipientId) === null;
    }

    /**
     * @param array<string, mixed> $sender
     */
    private function sendError(array $sender, int $recipientId): ?string
    {
        $senderId = (int) $sender['id'];
        if ($senderId <= 0 || $recipientId <= 0) {
            return 'Invalid recipient.';
        }

        if ($senderId === $recipientId) {
            return 'You cannot message yourself.';
        }

        $recipient = $this->users->findById($recipientId);
        if ($recipient === null || $this->users->isAnonymised($recipient)) {
            return 'User not found.';
        }

        if ($this->users->isBanned($recipient)) {
            return 'That user cannot receive messages.';
        }

        $senderIsStaff = $this->isStaff($sender);

        if (!$senderIsStaff) {
            if ($this->blocks->isBlocked($recipientId, $senderId)) {
                return 'That user is not accepting messages from you.';
            }

            if (!$this->users->acceptsMessages($recipient)) {
                $conversationId = $this->messages->findConversationId($senderId, $recipientId);
                if ($conversationId === null || !$this->messages->hasStaffMessage($conversationId)) {
                    return 'That user is not accepting messages from other members.';
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $sender
     */
    private function validateBody(string $body, array $sender): ?string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return 'Message cannot be empty.';
        }

        $linkError = $this->spamGuard->linkLimitError($trimmed, $sender);
        if ($linkError !== null) {
            return $linkError;
        }

        if (mb_strlen($trimmed) > 4000) {
            return 'Message is too long (maximum 4,000 characters).';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $sender
     */
    private function notifyRecipient(
        int $recipientId,
        array $sender,
        int $conversationId,
        string $kind,
        string $action,
    ): void {
        $senderName = (string) ($sender['username'] ?? 'Someone');
        $this->notifications->onDirectMessage(
            $recipientId,
            $sender,
            $conversationId,
            $kind,
            '@' . $senderName . ' ' . $action,
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    private function isStaff(array $user): bool
    {
        return in_array((string) ($user['role'] ?? ''), ['admin', 'mod'], true);
    }
}
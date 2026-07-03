<?php

declare(strict_types=1);

namespace Latch\Core;

use Latch\Models\NotificationRepository;
use Latch\Models\PostRepository;
use Latch\Models\UserRepository;

/**
 * Emits in-app notifications for replies, quotes, and staff actions.
 */
final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly UserRepository $users,
        private readonly ?EmailNotificationService $emailNotifications = null,
        private readonly MentionParser $mentions = new MentionParser(),
    ) {
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function onUserWarned(
        int $userId,
        array $actor,
        string $reason,
        ?int $reportId = null,
        ?int $conversationId = null,
    ): void {
        $actorName = (string) ($actor['username'] ?? 'Staff');
        $message = 'Staff issued you a warning: ' . $reason;
        $url = $conversationId !== null ? '/messages/' . $conversationId : '/messages';

        $this->notify(
            $userId,
            NotificationRepository::TYPE_USER_WARN,
            $message,
            $url,
            (int) $actor['id'],
            null,
            null,
            ['reason' => $reason, 'report_id' => $reportId, 'conversation_id' => $conversationId],
        );
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function onDirectMessage(
        int $userId,
        array $actor,
        int $conversationId,
        string $kind,
        string $message,
    ): void {
        $this->notify(
            $userId,
            NotificationRepository::TYPE_DIRECT_MESSAGE,
            $message,
            '/messages/' . $conversationId,
            (int) $actor['id'],
            null,
            null,
            ['conversation_id' => $conversationId, 'kind' => $kind],
        );
    }

    /**
     * @param array<string, mixed> $topic
     * @param array<string, mixed> $post
     * @param array<string, mixed> $actor
     */
    public function onReply(array $topic, array $post, array $actor): void
    {
        if (($post['approval_status'] ?? PostRepository::APPROVAL_APPROVED) !== PostRepository::APPROVAL_APPROVED) {
            return;
        }

        $actorId = (int) $actor['id'];
        $topicAuthorId = (int) $topic['user_id'];
        $postId = (int) $post['id'];
        $topicId = (int) $topic['id'];
        $topicTitle = (string) $topic['title'];
        $actorName = (string) $actor['username'];
        $url = '/topic/' . $topicId . '#post-' . $postId;

        $alreadyNotified = [];

        if ($actorId !== $topicAuthorId) {
            $this->notify(
                $topicAuthorId,
                NotificationRepository::TYPE_TOPIC_REPLY,
                '@' . $actorName . ' replied to your topic "' . $this->truncateTitle($topicTitle) . '"',
                $url,
                $actorId,
                $topicId,
                $postId,
                ['action' => 'reply'],
            );
            $alreadyNotified[$topicAuthorId] = true;
        }

        $alreadyNotified = $this->notifyQuotedUsers(
            (string) $post['body'],
            $actorId,
            $topicId,
            $postId,
            $topicTitle,
            $url,
            $actorName,
            $alreadyNotified,
        );

        $this->notifyMentionedUsers(
            (string) $post['body'],
            $actorId,
            $topicId,
            $postId,
            $topicTitle,
            $url,
            $actorName,
            $alreadyNotified,
        );
    }

    /**
     * Notify users newly @mentioned when a post body is edited.
     *
     * @param array<string, mixed> $topic
     * @param array<string, mixed> $post
     * @param array<string, mixed> $actor
     */
    public function onPostEdit(
        array $topic,
        array $post,
        array $actor,
        string $oldBody,
        string $newBody,
    ): void {
        if (($post['approval_status'] ?? PostRepository::APPROVAL_APPROVED) !== PostRepository::APPROVAL_APPROVED) {
            return;
        }

        $actorId = (int) $actor['id'];
        $postId = (int) $post['id'];
        $topicId = (int) $topic['id'];
        $topicTitle = (string) $topic['title'];
        $actorName = (string) $actor['username'];
        $url = '/topic/' . $topicId . '#post-' . $postId;

        $oldMentions = array_fill_keys($this->mentions->usernames($oldBody), true);
        $newMentions = $this->mentions->usernames($newBody);
        $alreadyNotified = [];

        foreach ($newMentions as $mentionedUsername) {
            if (isset($oldMentions[$mentionedUsername])) {
                continue;
            }

            $mentioned = $this->users->findByUsername($mentionedUsername);
            if ($mentioned === null) {
                continue;
            }

            $mentionedId = (int) $mentioned['id'];
            if ($mentionedId === $actorId || isset($alreadyNotified[$mentionedId])) {
                continue;
            }

            $this->notify(
                $mentionedId,
                NotificationRepository::TYPE_MENTION,
                '@' . $actorName . ' mentioned you in "' . $this->truncateTitle($topicTitle) . '"',
                $url,
                $actorId,
                $topicId,
                $postId,
                ['mentioned_username' => $mentionedUsername, 'action' => 'edit'],
            );
            $alreadyNotified[$mentionedId] = true;
        }
    }

    /**
     * Notify the author when their post enters the mod approval queue.
     *
     * @param array<string, mixed> $topic
     * @param array<string, mixed> $post
     * @param array<string, mixed> $author
     */
    public function onPostPendingApproval(
        array $topic,
        array $post,
        array $author,
        bool $isNewTopic = false,
    ): void {
        if (($post['approval_status'] ?? PostRepository::APPROVAL_APPROVED) !== PostRepository::APPROVAL_PENDING) {
            return;
        }

        $authorId = (int) $author['id'];
        $topicId = (int) $topic['id'];
        $postId = (int) $post['id'];
        $topicTitle = $this->truncateTitle((string) $topic['title']);
        $url = '/topic/' . $topicId . '#post-' . $postId;

        $message = $isNewTopic
            ? 'Your topic "' . $topicTitle . '" is awaiting staff approval'
            : 'Your reply in "' . $topicTitle . '" is awaiting staff approval';

        $this->notify(
            $authorId,
            NotificationRepository::TYPE_POST_PENDING,
            $message,
            $url,
            null,
            $topicId,
            $postId,
            ['action' => 'pending_approval', 'is_new_topic' => $isNewTopic],
        );
    }

    /**
     * @param array<string, mixed> $topic
     * @param array<string, mixed> $actor
     */
    public function onStaffTopicAction(
        string $action,
        array $topic,
        array $actor,
        string $message,
    ): void {
        $topicAuthorId = (int) $topic['user_id'];
        $actorId = (int) $actor['id'];

        if ($actorId === $topicAuthorId) {
            return;
        }

        $this->notify(
            $topicAuthorId,
            NotificationRepository::TYPE_STAFF_ACTION,
            $message,
            '/topic/' . (int) $topic['id'],
            $actorId,
            (int) $topic['id'],
            null,
            ['action' => $action, 'target' => 'topic'],
        );
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $topic
     * @param array<string, mixed> $actor
     */
    public function onStaffPostAction(
        string $action,
        array $post,
        array $topic,
        array $actor,
        string $message,
    ): void {
        $postAuthorId = (int) $post['user_id'];
        $actorId = (int) $actor['id'];

        if ($actorId === $postAuthorId) {
            return;
        }

        $this->notify(
            $postAuthorId,
            NotificationRepository::TYPE_STAFF_ACTION,
            $message,
            '/topic/' . (int) $topic['id'] . '#post-' . (int) $post['id'],
            $actorId,
            (int) $topic['id'],
            (int) $post['id'],
            ['action' => $action, 'target' => 'post'],
        );
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $topic
     */
    public function onPostQuarantined(array $post, array $topic, ?int $actorId = null): void
    {
        $postAuthorId = (int) $post['user_id'];
        if ($actorId !== null && $actorId === $postAuthorId) {
            return;
        }

        $topicTitle = (string) $topic['title'];
        $message = 'Your post in "' . $this->truncateTitle($topicTitle) . '" is under staff review';

        $this->notify(
            $postAuthorId,
            NotificationRepository::TYPE_STAFF_ACTION,
            $message,
            '/topic/' . (int) $topic['id'] . '#post-' . (int) $post['id'],
            $actorId,
            (int) $topic['id'],
            (int) $post['id'],
            ['action' => 'quarantine', 'target' => 'post'],
        );
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $topic
     * @param array<string, mixed> $actor
     */
    public function onPostLiked(array $post, array $topic, array $actor): void
    {
        $authorId = (int) $post['user_id'];
        $actorId = (int) $actor['id'];
        if ($actorId === $authorId) {
            return;
        }

        $topicTitle = (string) $topic['title'];
        $actorName = (string) $actor['username'];

        $this->notify(
            $authorId,
            NotificationRepository::TYPE_POST_LIKE,
            '@' . $actorName . ' liked your post in "' . $this->truncateTitle($topicTitle) . '"',
            '/topic/' . (int) $topic['id'] . '#post-' . (int) $post['id'],
            $actorId,
            (int) $topic['id'],
            (int) $post['id'],
            null,
        );
    }

    /**
     * @return list<string>
     */
    public function quotedUsernames(string $body): array
    {
        if (!preg_match_all('/\[quote(?:="([^"]*)"| author="([^"]*)")?\]/i', $body, $matches)) {
            return [];
        }

        $names = [];
        $count = count($matches[0]);
        for ($i = 0; $i < $count; $i++) {
            $name = trim($matches[1][$i] !== '' ? $matches[1][$i] : $matches[2][$i]);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array<int, true> $alreadyNotified
     * @return array<int, true>
     */
    private function notifyQuotedUsers(
        string $body,
        int $actorId,
        int $topicId,
        int $postId,
        string $topicTitle,
        string $url,
        string $actorName,
        array $alreadyNotified,
    ): array {
        foreach ($this->quotedUsernames($body) as $quotedUsername) {
            $quoted = $this->users->findByUsername($quotedUsername);
            if ($quoted === null) {
                continue;
            }

            $quotedId = (int) $quoted['id'];
            if ($quotedId === $actorId || isset($alreadyNotified[$quotedId])) {
                continue;
            }

            $this->notify(
                $quotedId,
                NotificationRepository::TYPE_POST_QUOTE,
                '@' . $actorName . ' quoted your post in "' . $this->truncateTitle($topicTitle) . '"',
                $url,
                $actorId,
                $topicId,
                $postId,
                ['quoted_username' => $quotedUsername],
            );
            $alreadyNotified[$quotedId] = true;
        }

        return $alreadyNotified;
    }

    /**
     * @param array<int, true> $alreadyNotified
     */
    private function notifyMentionedUsers(
        string $body,
        int $actorId,
        int $topicId,
        int $postId,
        string $topicTitle,
        string $url,
        string $actorName,
        array $alreadyNotified,
    ): void {
        foreach ($this->mentions->usernames($body) as $mentionedUsername) {
            $mentioned = $this->users->findByUsername($mentionedUsername);
            if ($mentioned === null) {
                continue;
            }

            $mentionedId = (int) $mentioned['id'];
            if ($mentionedId === $actorId || isset($alreadyNotified[$mentionedId])) {
                continue;
            }

            $this->notify(
                $mentionedId,
                NotificationRepository::TYPE_MENTION,
                '@' . $actorName . ' mentioned you in "' . $this->truncateTitle($topicTitle) . '"',
                $url,
                $actorId,
                $topicId,
                $postId,
                ['mentioned_username' => $mentionedUsername],
            );
            $alreadyNotified[$mentionedId] = true;
        }
    }

    private function notify(
        int $userId,
        string $eventType,
        string $message,
        string $url,
        ?int $actorId,
        ?int $topicId,
        ?int $postId,
        ?array $meta = null,
    ): void {
        if ($userId <= 0) {
            return;
        }

        $this->notifications->create(
            $userId,
            $eventType,
            $message,
            $url,
            $actorId,
            $topicId,
            $postId,
            $meta,
        );

        $this->emailNotifications?->maybeSend($userId, $eventType, $message, $url);
    }

    private function truncateTitle(string $title, int $max = 80): string
    {
        if (mb_strlen($title) <= $max) {
            return $title;
        }

        return mb_substr($title, 0, $max - 1) . '…';
    }
}
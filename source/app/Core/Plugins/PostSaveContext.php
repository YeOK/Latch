<?php

declare(strict_types=1);

namespace Latch\Core\Plugins;

/**
 * Mutable context passed to post.before_save / post.after_save hooks.
 */
final class PostSaveContext
{
    public ?string $rejectReason = null;

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $board
     * @param array<string, mixed>|null $topic
     * @param array<string, mixed>|null $post
     */
    public function __construct(
        public string $body,
        public readonly array $user,
        public readonly array $board,
        public ?array $topic,
        public readonly string $kind,
        public ?array $post = null,
        public readonly ?string $topicTitle = null,
    ) {
    }

    public function reject(string $reason): void
    {
        $this->rejectReason = $reason;
    }
}
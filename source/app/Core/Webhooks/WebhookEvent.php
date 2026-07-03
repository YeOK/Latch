<?php

declare(strict_types=1);

namespace Latch\Core\Webhooks;

final class WebhookEvent
{
    public const POST_CREATED = 'post.created';
    public const USER_REGISTERED = 'user.registered';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::POST_CREATED,
            self::USER_REGISTERED,
        ];
    }

    public static function isValid(string $event): bool
    {
        return in_array($event, self::all(), true);
    }
}
<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\NotificationMessageFormatter;
use Latch\Core\Plugins\HookRegistry;
use Latch\Core\Translator;
use Latch\Models\NotificationRepository;
use PHPUnit\Framework\TestCase;

final class NotificationMessageFormatterTest extends TestCase
{
    private NotificationMessageFormatter $formatter;
    private Translator $translator;

    protected function setUp(): void
    {
        $this->formatter = new NotificationMessageFormatter();
        $this->translator = new Translator(dirname(__DIR__) . '/lang', 'es', new HookRegistry());
    }

    public function testFormatsTopicReplyInRecipientLocale(): void
    {
        $message = $this->formatter->format([
            'event_type' => NotificationRepository::TYPE_TOPIC_REPLY,
            'message' => '@alice replied to your topic "Hello world"',
            'actor_username' => 'alice',
            'meta' => ['topic_title' => 'Hello world'],
        ], $this->translator);

        $this->assertStringContainsString('@alice', $message);
        $this->assertStringContainsString('Hello world', $message);
        $this->assertStringContainsString('respondió', $message);
    }

    public function testFormatsStaffLockFromMetaAction(): void
    {
        $message = $this->formatter->format([
            'event_type' => NotificationRepository::TYPE_STAFF_ACTION,
            'message' => 'Your topic "Draft" was locked by @mod',
            'actor_username' => 'mod',
            'meta' => ['action' => 'topic.lock', 'topic_title' => 'Draft'],
        ], $this->translator);

        $this->assertStringContainsString('Draft', $message);
        $this->assertStringContainsString('@mod', $message);
    }

    public function testFallsBackToStoredMessageForDirectMessages(): void
    {
        $stored = 'Hey — can you take a look?';
        $message = $this->formatter->format([
            'event_type' => NotificationRepository::TYPE_DIRECT_MESSAGE,
            'message' => $stored,
            'meta' => [],
        ], $this->translator);

        $this->assertSame($stored, $message);
    }

    public function testParsesLegacyEnglishTitleWhenMetaMissing(): void
    {
        $message = $this->formatter->format([
            'event_type' => NotificationRepository::TYPE_POST_LIKE,
            'message' => '@bob liked your post in "Legacy topic"',
            'actor_username' => 'bob',
            'meta' => [],
        ], $this->translator);

        $this->assertStringContainsString('Legacy topic', $message);
        $this->assertStringContainsString('@bob', $message);
    }
}
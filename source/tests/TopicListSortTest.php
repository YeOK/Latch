<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Support\TopicListSort;
use PHPUnit\Framework\TestCase;

final class TopicListSortTest extends TestCase
{
    public function testNormalizeDefaultsUnknownToActivity(): void
    {
        $this->assertSame(TopicListSort::ACTIVITY, TopicListSort::normalize(null));
        $this->assertSame(TopicListSort::ACTIVITY, TopicListSort::normalize('nope'));
        $this->assertSame(TopicListSort::REPLIES, TopicListSort::normalize('replies'));
    }

    public function testOrderBySqlUsesLastPostForActivity(): void
    {
        $this->assertStringContainsString('last_post_at DESC', TopicListSort::orderBySql(TopicListSort::ACTIVITY));
        $this->assertStringContainsString('created_at DESC', TopicListSort::orderBySql(TopicListSort::NEWEST));
        $this->assertStringContainsString('last_post_at ASC', TopicListSort::orderBySql(TopicListSort::OLDEST));
        $this->assertStringContainsString('post_count DESC', TopicListSort::orderBySql(TopicListSort::REPLIES));
    }
}
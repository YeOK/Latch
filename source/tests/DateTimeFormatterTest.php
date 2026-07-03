<?php

declare(strict_types=1);

use Latch\Core\DateTimeFormatter;
use PHPUnit\Framework\TestCase;

final class DateTimeFormatterTest extends TestCase
{
    private DateTimeFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new DateTimeFormatter();
    }

    public function testFormatReplacesIsoSeparator(): void
    {
        $this->assertSame('2026-06-29 22:51', $this->formatter->format('2026-06-29T22:51:00+00:00'));
    }

    public function testFormatDate(): void
    {
        $this->assertSame('2026-06-29', $this->formatter->formatDate('2026-06-29T22:51:00+00:00'));
    }

    public function testFormatEmptyValue(): void
    {
        $this->assertSame('', $this->formatter->format(null));
        $this->assertSame('', $this->formatter->format(''));
    }
}
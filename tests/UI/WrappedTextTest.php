<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPolygon\Rendering\TextWrap;
use PHPolygon\UI\WrappedText;
use PHPUnit\Framework\TestCase;

final class WrappedTextTest extends TestCase
{
    /** @return callable(string): float 10 px per character */
    private function measure(): callable
    {
        return static fn(string $s): float => mb_strlen($s) * 10.0;
    }

    public function testItReportsLinesWidthAndHeight(): void
    {
        $text = WrappedText::fit('one two three', 70.0, $this->measure(), 12.0);

        self::assertSame("one two\nthree", $text->text);
        self::assertSame(2, $text->lines);
        self::assertSame(70.0, $text->width);
        self::assertSame(24.0, $text->height);
        self::assertSame(12.0, $text->pitch);
    }

    public function testEmptyTextIsOneEmptyLine(): void
    {
        $text = WrappedText::fit('', 70.0, $this->measure(), 12.0);

        self::assertSame(1, $text->lines);
        self::assertSame(0.0, $text->width);
    }

    public function testAnOverlongWordIsSplit(): void
    {
        $text = WrappedText::fit('abcdefghij', 40.0, $this->measure(), 12.0);

        self::assertSame("abcd\nefgh\nij", $text->text);
        self::assertSame(40.0, $text->width);
    }

    public function testThaiMarksStayOnTheirConsonant(): void
    {
        $text = WrappedText::fit('ที่นี่กำลังสุดท้าย', 20.0, $this->measure(), 12.0);

        foreach (explode("\n", $text->text) as $line) {
            self::assertSame(0, preg_match('/^\p{M}/u', $line), $line);
        }
    }

    public function testTheRendererLeavesTheLinesAlone(): void
    {
        // A renderer wraps the joined text again at its break width: hard breaks
        // stay, and no line of the width it reports is broken a second time.
        $measure = $this->measure();
        $text = WrappedText::fit('one two three four abcdefghijklmnop', 60.0, $measure, 12.0);

        self::assertSame(
            explode("\n", $text->text),
            TextWrap::lines($text->text, $text->width + 1.0, $measure),
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Rect;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Label;

/**
 * A wrapping label breaks where a line runs out of room, not only at spaces.
 * Scripts written without spaces (Chinese, Japanese) and single words longer
 * than the label is wide must still stay inside its bounds, and CJK glyphs
 * are measured as the full-width glyphs they are.
 */
class LabelWrapTest extends TestCase
{
    private const FONT_SIZE = 10.0;

    /** @return list<string> */
    private function drawnLines(string $text, float $width): array
    {
        $label = new Label($text);
        $label->wrap = true;
        $label->fontSize = self::FONT_SIZE;
        $label->setBounds(new Rect(0.0, 0.0, $width, 400.0));
        $renderer = new WidgetTestHelper();
        $label->draw($renderer, UIStyle::dark());

        $lines = [];
        foreach ($renderer->calls as $call) {
            if ($call['method'] === 'drawText' && is_string($call['args'][0])) {
                $lines[] = $call['args'][0];
            }
        }

        return $lines;
    }

    private function measuredHeight(string $text, float $width): float
    {
        $label = new Label($text);
        $label->wrap = true;
        $label->fontSize = self::FONT_SIZE;
        $label->sizing->width = $width;
        $label->measure(1000.0, 1000.0, UIStyle::dark());

        return $label->getMeasuredHeight();
    }

    public function testLatinWrapsAtSpacesAsBefore(): void
    {
        // 6.2 per char at size 10: 62 px hold ten characters.
        $this->assertSame(['aaaa bbbb', 'cccc dddd'], $this->drawnLines('aaaa bbbb cccc dddd', 62.0));
    }

    public function testTextWithoutSpacesBreaksBetweenCjkCharacters(): void
    {
        // Full-width glyphs advance a whole font size: 50 px hold five.
        $text = 'あいうえおかきくけこさしすせそ';

        $this->assertSame(['あいうえお', 'かきくけこ', 'さしすせそ'], $this->drawnLines($text, 50.0));
    }

    public function testMeasuredHeightCountsTheCjkLines(): void
    {
        $text = 'あいうえおかきくけこさしすせそ';

        $this->assertEqualsWithDelta(3 * self::FONT_SIZE * 1.3, $this->measuredHeight($text, 50.0), 0.001);
    }

    public function testClosingPunctuationStaysWithThePrecedingCharacter(): void
    {
        $this->assertSame(['あいうえ', 'お。かき'], $this->drawnLines('あいうえお。かき', 50.0));
    }

    public function testMixedLatinAndCjkKeepsTheSpaceBetweenWords(): void
    {
        $this->assertSame(['F8 キーを', '押す'], $this->drawnLines('F8 キーを押す', 50.0));
    }

    public function testAWordLongerThanTheLineIsSplit(): void
    {
        $this->assertSame(['go', 'abcdefghij', 'klm'], $this->drawnLines('go abcdefghijklm', 62.0));
    }

    public function testASplitWordKeepsCombiningMarksWithTheirLetter(): void
    {
        // Thai writes words without spaces, so a long run is split to fit. A
        // tone or vowel mark belongs to the consonant before it; a line that
        // starts with the mark alone draws a dangling glyph (and has crashed a
        // renderer).
        $text = str_repeat("\u{0E01}\u{0E48}\u{0E32}", 12); // กา with a tone mark, twelve times

        foreach ([20.0, 31.0, 44.0, 57.0] as $width) {
            foreach ($this->drawnLines($text, $width) as $line) {
                $this->assertDoesNotMatchRegularExpression('/^\p{M}/u', $line, "width {$width}");
            }
            $this->assertSame($text, implode('', $this->drawnLines($text, $width)), 'no character is lost');
        }
    }

    public function testHardBreaksStillStartNewLines(): void
    {
        $this->assertSame(['ab', 'cd'], $this->drawnLines("ab\ncd", 62.0));
    }

    public function testSingleLineWidthOfCjkTextIsFullWidth(): void
    {
        $label = new Label('日本語');
        $label->fontSize = self::FONT_SIZE;
        $label->measure(1000.0, 1000.0, UIStyle::dark());

        $this->assertEqualsWithDelta(30.0, $label->getMeasuredWidth() - $label->padding->horizontal(), 0.001);
    }
}

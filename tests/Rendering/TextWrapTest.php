<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\TextWrap;
use PHPUnit\Framework\TestCase;

/**
 * Line breaking, measured with a deliberately simple width model.
 *
 * The measurer below charges 1.0 per Latin character and 2.0 per CJK
 * character — the real ratio is close to that, and a fixed model keeps these
 * expectations readable: a break width of 10.0 is "ten Latin characters, or
 * five CJK ones".
 */
final class TextWrapTest extends TestCase
{
    /** @return callable(string): float */
    private function measurer(): callable
    {
        return static function (string $s): float {
            $width = 0.0;
            foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
                $width += preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $c) === 1 ? 2.0 : 1.0;
            }

            return $width;
        };
    }

    /** @return list<string> */
    private function wrap(string $text, float $width): array
    {
        return TextWrap::lines($text, $width, $this->measurer());
    }

    // ── Latin: unchanged behaviour ──────────────────────────────────────

    public function testShortTextStaysOnOneLine(): void
    {
        self::assertSame(['hello'], $this->wrap('hello', 10.0));
    }

    public function testLatinWrapsAtSpaces(): void
    {
        self::assertSame(['alpha', 'beta', 'gamma'], $this->wrap('alpha beta gamma', 6.0));
    }

    public function testAWordWiderThanTheColumnKeepsItsOwnLine(): void
    {
        // Never split mid-word: the long word overflows on a line of its own.
        self::assertSame(['ok', 'extraordinarily'], $this->wrap('ok extraordinarily', 5.0));
    }

    public function testHardBreaksAlwaysSplit(): void
    {
        self::assertSame(['a', 'b'], $this->wrap("a\nb", 100.0));
    }

    public function testAnEmptyParagraphYieldsABlankLine(): void
    {
        self::assertSame(['a', '', 'b'], $this->wrap("a\n\nb", 100.0));
    }

    // ── CJK: the reason this class exists ───────────────────────────────

    public function testJapaneseBreaksBetweenCharacters(): void
    {
        // Six characters at 2.0 each = 12.0; a column of 6.0 holds three.
        self::assertSame(['課題を', '解いて'], $this->wrap('課題を解いて', 6.0));
    }

    public function testJapaneseWithoutSpacesNoLongerOverflows(): void
    {
        $lines = $this->wrap('電力なしゲートで送電', 8.0);

        self::assertGreaterThan(1, count($lines), 'a spaceless run must still break');
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(8.0, ($this->measurer())($line), "line too wide: {$line}");
        }
    }

    public function testALineNeverStartsWithClosingPunctuation(): void
    {
        // Without the rule the break would fall before 。 and strand it.
        foreach ($this->wrap('あいう。えお。', 6.0) as $line) {
            self::assertStringStartsNotWith('。', $line);
        }
    }

    public function testALineNeverStartsWithASmallKana(): void
    {
        foreach ($this->wrap('きゃきゅきょきゃきゅきょ', 6.0) as $line) {
            self::assertDoesNotMatchRegularExpression('/^[ぁぃぅぇぉっゃゅょ]/u', $line);
        }
    }

    public function testALineNeverEndsWithOpeningPunctuation(): void
    {
        $lines = $this->wrap('あい「うえおかきくけこ」', 6.0);

        foreach ($lines as $line) {
            self::assertStringEndsNotWith('「', $line);
        }
    }

    public function testMixedLatinAndJapaneseKeepsTheLatinWordIntact(): void
    {
        // "Reef" must not be torn apart, but the Japanese run around it may break.
        $lines = $this->wrap('電力なし Reef に送電', 8.0);

        self::assertNotEmpty($lines);
        $joined = implode('|', $lines);
        self::assertStringContainsString('Reef', $joined, 'the Latin word survives as a unit');
    }

    public function testSpacesAreNotReintroducedBetweenCjkCharacters(): void
    {
        // Rejoining must not invent a space that the author never wrote.
        self::assertSame('課題を解いて', implode('', $this->wrap('課題を解いて', 6.0)));
    }

    public function testASplitWordNeverStartsALineWithACombiningMark(): void
    {
        // Thai vowels above/below and tone marks are separate code points that
        // only make sense on their consonant; so are decomposed Latin accents.
        foreach (['ที่นี่ที่นั่นกำลังสุดท้าย', "e\u{0301}e\u{0301}e\u{0301}e\u{0301}e\u{0301}"] as $word) {
            foreach ([1.0, 2.0, 3.0, 4.0] as $width) {
                $lines = TextWrap::linesWithin($word, $width, $this->measurer());

                self::assertSame($word, implode('', $lines), 'nothing lost');
                foreach ($lines as $line) {
                    self::assertSame(0, preg_match('/^\p{M}/u', $line), "line starts with a mark at width $width");
                }
            }
        }
    }

    public function testAGraphemeWiderThanTheBoxStaysWhole(): void
    {
        self::assertSame(['กำ', 'ลั', 'ง'], TextWrap::linesWithin('กำลัง', 1.0, $this->measurer()));
    }

    public function testLatinSpacingSurvivesARoundTrip(): void
    {
        self::assertSame('alpha beta gamma', implode(' ', $this->wrap('alpha beta gamma', 6.0)));
    }
}

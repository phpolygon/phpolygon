<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Text;

use PHPolygon\Rendering\Text\BidiOrder;
use PHPUnit\Framework\TestCase;

/**
 * Visual order of mixed-direction UI strings: the order {@see \PHPolygon\Rendering\VioRenderer2D}
 * places font runs in once a string with Arabic or Hebrew is split across a fallback chain.
 */
final class BidiOrderTest extends TestCase
{
    /** The characters of $text from left to right on screen. */
    private static function visual(string $text): string
    {
        $chars = mb_str_split($text);
        $out = '';
        foreach (BidiOrder::visualOrder(BidiOrder::levels($chars)['levels']) as $i) {
            $out .= $chars[$i];
        }

        return $out;
    }

    public function testLatinStaysInLogicalOrder(): void
    {
        self::assertSame('Noch nicht richtig – versuch es nochmal!', self::visual('Noch nicht richtig – versuch es nochmal!'));
        self::assertSame(0, BidiOrder::levels(mb_str_split('abc 123'))['base']);
    }

    public function testAnArabicSentenceRunsRightToLeft(): void
    {
        // Logical: "ليس تمامًا – حاول مجددًا!" -> the closing "!" on the far left, "ليس" on the far right.
        $text = 'ليس تمامًا – حاول مجددًا!';
        $levels = BidiOrder::levels(mb_str_split($text));
        self::assertSame(1, $levels['base']);
        $visual = self::visual($text);
        self::assertSame('!', mb_substr($visual, 0, 1));
        self::assertSame(self::reverse('ليس'), mb_substr($visual, -3));
        self::assertSame(self::reverse($text), $visual, 'pure RTL text with neutrals is the exact reverse');
    }

    public function testNumbersAndLatinInsideArabicKeepTheirOwnOrder(): void
    {
        // "تلميح (0/3)": the number reads 0/3; the brackets sit right to left, so they
        // come out swapped until mirrored (the renderer draws mirror()).
        $visual = self::visual('تلميح (0/3)');
        self::assertSame(')0/3( ' . self::reverse('تلميح'), $visual);

        // A code identifier inside an Arabic sentence stays left to right, sigil in front.
        $visual = self::visual('يتم تعيين $language');
        self::assertSame('$language ' . self::reverse('يتم تعيين'), $visual);
    }

    public function testArabicInsideALatinParagraphIsReversedInPlace(): void
    {
        self::assertSame('Name: ' . self::reverse('عربي') . ' ok', self::visual('Name: عربي ok'));
    }

    public function testTrailingSpaceStaysAtTheParagraphEnd(): void
    {
        $levels = BidiOrder::levels(mb_str_split('عربي '))['levels'];
        self::assertSame(1, $levels[4], 'trailing whitespace takes the paragraph level');
    }

    public function testMarksFollowTheirBaseAndMirroringSwapsBrackets(): void
    {
        $levels = BidiOrder::levels(mb_str_split('تمامًا'))['levels'];
        self::assertSame([1, 1, 1, 1, 1, 1], $levels, 'the tanween mark stays in its word');
        self::assertSame(')', BidiOrder::mirror('('));
        self::assertSame('«', BidiOrder::mirror('»'));
        self::assertSame('-', BidiOrder::mirror('-'));
    }

    public function testAWrappedLineKeepsTheParagraphDirection(): void
    {
        $paragraph = 'احفظ النص في المتغير $language. في PHP يبدأ كل متغير بـ $';
        $line2 = '$language. في PHP يبدأ كل متغير بـ $';
        self::assertSame(0, BidiOrder::levels(mb_str_split($line2))['base'], 'alone the line looks left to right');
        self::assertSame(1, BidiOrder::levels(mb_str_split($line2), 1)['base']);

        $lines = BidiOrder::keepParagraphDirection($paragraph, ['احفظ النص في المتغير', $line2, ';.']);
        self::assertSame(['احفظ النص في المتغير', "\u{200F}" . $line2, "\u{200F};."], $lines);
        self::assertSame(1, BidiOrder::levels(mb_str_split($lines[1]))['base']);

        self::assertSame(['Store "PHP"', 'in $language'], BidiOrder::keepParagraphDirection('Store "PHP" in $language', ['Store "PHP"', 'in $language']));
        self::assertSame(['Name:', "\u{200E}عربي ok"], BidiOrder::keepParagraphDirection('Name: عربي ok', ['Name:', 'عربي ok']));
    }

    public function testDetection(): void
    {
        self::assertTrue(BidiOrder::hasRtl('طرفية W1_L01'));
        self::assertTrue(BidiOrder::hasRtl('שלום'));
        self::assertFalse(BidiOrder::hasRtl('简体中文 ไทย Ελληνικά – …'));
        self::assertTrue(BidiOrder::isStrongRtl('ع'));
        self::assertFalse(BidiOrder::isStrongRtl('٣'), 'Arabic-Indic digits are numbers');
    }

    private static function reverse(string $s): string
    {
        return implode('', array_reverse(mb_str_split($s)));
    }
}

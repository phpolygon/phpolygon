<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPUnit\Framework\TestCase;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Button;

/**
 * A button without a fixed width is as wide as its label. Full-width glyphs
 * (CJK, Hangul) take a whole font size each; counting them as regular glyphs
 * made such buttons too small, and a row of them overlapped.
 */
class ButtonMeasureTest extends TestCase
{
    private function widthOf(string $label): float
    {
        $button = new Button($label);
        $style = UIStyle::dark();
        $button->measure(1000.0, 100.0, $style);

        return $button->getMeasuredWidth() - $button->padding->horizontal();
    }

    public function testFullWidthGlyphsTakeAWholeFontSize(): void
    {
        $fontSize = UIStyle::dark()->fontSize;

        $this->assertEqualsWithDelta(4 * $fontSize, $this->widthOf('名前を変'), 0.001);
    }

    public function testRegularGlyphsKeepTheirNarrowAdvance(): void
    {
        $fontSize = UIStyle::dark()->fontSize;

        $this->assertEqualsWithDelta(4 * 0.62 * $fontSize, $this->widthOf('Save'), 0.001);
    }

    public function testAButtonIsNeverNarrowerThanALabelWithTheSameText(): void
    {
        $text = 'マーケティング';
        $label = new \PHPolygon\UI\Widget\Label($text);
        $style = UIStyle::dark();
        $label->fontSize = $style->fontSize;
        $label->measure(1000.0, 100.0, $style);

        $this->assertGreaterThanOrEqual(
            $label->getMeasuredWidth() - $label->padding->horizontal(),
            $this->widthOf($text),
        );
    }
}

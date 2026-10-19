<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\Runtime\Input;
use PHPolygon\UI\UIContext;
use PHPolygon\UI\UIStyle;
use PHPUnit\Framework\TestCase;

/**
 * wrapButton(): labels wrap instead of being cut, the height follows the
 * lines, a row shares one size; layoutButtonRow(): widths follow the labels.
 * NullRenderer2D measures 0.6 * size per byte.
 */
final class UIContextWrapButtonTest extends TestCase
{
    /** @var list<array{string, float, float, float}> text, cx, cy, size */
    private array $texts = [];
    /** @var list<array{float, float, float, float}> */
    private array $outlines = [];
    private Input $input;
    private UIContext $ui;

    protected function setUp(): void
    {
        $test = $this;
        $renderer = new class(1280, 720, $test) extends NullRenderer2D {
            public function __construct(int $w, int $h, private readonly UIContextWrapButtonTest $test)
            {
                parent::__construct($w, $h);
            }

            public function drawTextCentered(string $text, float $cx, float $cy, float $size, Color $color): void
            {
                $this->test->text($text, $cx, $cy, $size);
            }

            public function drawRoundedRectOutline(float $x, float $y, float $w, float $h, float $radius, Color $color, float $lineWidth = 1.0): void
            {
                $this->test->outline($x, $y, $w, $h);
            }
        };
        $this->input = new Input();
        $style = new UIStyle(fontSize: 10.0, padding: 5.0, buttonPaddingH: 10.0);
        $this->ui = new UIContext($renderer, $this->input, $style);
    }

    public function text(string $text, float $cx, float $cy, float $size): void
    {
        $this->texts[] = [$text, $cx, $cy, $size];
    }

    public function outline(float $x, float $y, float $w, float $h): void
    {
        $this->outlines[] = [$x, $y, $w, $h];
    }

    /** @return list<string> */
    private function drawnTexts(): array
    {
        return array_map(static fn(array $t): string => $t[0], $this->texts);
    }

    public function testALongLabelWrapsAndTheButtonGrows(): void
    {
        $this->ui->begin(0.0, 0.0, 100.0);
        $this->ui->wrapButton('b', 'Skip all of it now', 100.0);
        $y = $this->ui->getCursorY();
        $this->ui->end();

        // 80 px inside: "Skip all of it" (14 * 6 = 84) does not fit, so two lines.
        self::assertSame(['Skip all of', 'it now'], $this->drawnTexts());
        self::assertSame($this->ui->rowHeight() + 13.0 + 4.0, $y, 'height = one row + one more line of 13, plus item spacing');
        self::assertGreaterThan($this->texts[0][2], $this->texts[1][2], 'second line below the first');
    }

    public function testAShortLabelKeepsTheRowHeight(): void
    {
        $this->ui->begin(0.0, 0.0, 100.0);
        $this->ui->wrapButton('b', 'OK', 100.0);
        $y = $this->ui->getCursorY();
        $this->ui->end();

        self::assertSame($this->ui->rowHeight() + 4.0, $y);
        self::assertSame([['OK', 50.0, 10.0, 10.0]], $this->texts);
    }

    public function testRowLabelsShareOneSizeAndHeight(): void
    {
        $fit = $this->ui->fitButtonLabels(['A', 'Abcdefghijklmn'], 60.0, maxLines: 2, minFontSize: 6.0);
        // 40 px inside: the 14-letter word is 50.4 wide even at the minimum 6, so it is split - but kept whole.
        self::assertSame(6.0, $fit->fontSize);
        self::assertSame(['A'], $fit->label(0));
        self::assertSame('Abcdefghijklmn', implode('', $fit->label(1)), 'nothing cut');

        $h = $this->ui->wrapButtonHeight($fit->lineCount(), $fit->fontSize);
        $this->ui->begin(0.0, 0.0, 60.0);
        $this->ui->wrapButton('a', $fit->label(0), 60.0, height: $h, fontSize: $fit->fontSize);
        $this->ui->end();
        self::assertSame(6.0, $this->texts[0][3]);
        self::assertSame($h * 0.5, $this->texts[0][2], 'a one-line label is centred in the taller button');
    }

    public function testKeycapSitsLeftAndLeadingSpaceIsReported(): void
    {
        $this->ui->begin(0.0, 0.0, 200.0);
        $this->ui->wrapButton('b', 'Text', 200.0, keycap: '2', leadingSpace: 20.0);
        $this->ui->end();

        $k = $this->ui->keycapSize();
        self::assertSame([10.0, round($this->ui->rowHeight() * 0.5 - $k * 0.5), $k, $k], $this->outlines[0], 'key cap at the left padding, centred');
        self::assertSame('2', $this->texts[0][0]);
        self::assertSame(7.0, $this->texts[0][3], 'key cap digit is smaller than the label');
        $leading = $this->ui->lastButtonLeading();
        self::assertSame(10.0 + $k + 12.0, $leading->x);
        self::assertSame(20.0, $leading->width);
        // Label centred between the leading space and the right padding.
        self::assertSame(round(($leading->x + 20.0 + 190.0) * 0.5), $this->texts[1][1]);
    }

    public function testLeftAlignStartsAtTheContentEdge(): void
    {
        $this->ui->begin(0.0, 0.0, 200.0);
        $this->ui->wrapButton('b', 'abc', 200.0, align: TextAlign::LEFT);
        $this->ui->end();

        self::assertSame(round(10.0 + 18.0 * 0.5), $this->texts[0][1]);
    }

    public function testItClicksLikeAButton(): void
    {
        $this->input->handleCursorPosEvent(15.0, 15.0);
        $this->input->handleMouseButtonEvent(0, 1);
        $this->ui->begin(10.0, 10.0, 100.0);
        $this->ui->wrapButton('b', 'Go', 100.0);
        $this->ui->end();

        $this->input->unsuppress();
        $this->input->endFrame();
        $this->input->handleCursorPosEvent(15.0, 15.0);
        $this->input->handleMouseButtonEvent(0, 0);
        $this->ui->begin(10.0, 10.0, 100.0);
        $clicked = $this->ui->wrapButton('b', 'Go', 100.0);
        $this->ui->end();

        self::assertTrue($clicked);
    }

    public function testRowWidthsFollowTheLabels(): void
    {
        // Natural widths: "Go" 12+20=32, "Next station" 72+20=92; room 200-10 = 190, extra 33 each.
        $row = $this->ui->layoutButtonRow(['Go', 'Next station'], 200.0, gap: 10.0);

        self::assertSame([65.0, 125.0], $row->widths);
        self::assertSame([0.0, 75.0], $row->xs);
        self::assertSame([['Go'], ['Next station']], $row->lines);
        self::assertSame(10.0, $row->fontSize);
        self::assertSame($this->ui->rowHeight(), $row->height);
    }

    public function testARowThatIsTooNarrowShrinksThenWrapsTheWidestLabel(): void
    {
        // Room 90. At 10: 32 + 92 = 124. At the minimum 8: 30 + 78 = 108 - still too wide, so wrap.
        $row = $this->ui->layoutButtonRow(['Go', 'Next station'], 100.0, gap: 10.0, minFontSize: 8.0);

        self::assertSame(8.0, $row->fontSize);
        self::assertSame(['Next', 'station'], $row->lines[1], 'the widest label goes to two lines');
        self::assertSame(['Go'], $row->lines[0]);
        self::assertLessThanOrEqual(100.0, $row->xs[1] + $row->widths[1]);
        self::assertSame($this->ui->wrapButtonHeight(2, 8.0), $row->height);
    }
}

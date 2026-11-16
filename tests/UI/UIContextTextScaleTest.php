<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Runtime\Input;
use PHPolygon\UI\TextScale;
use PHPolygon\UI\UIContext;
use PHPolygon\UI\UIStyle;
use PHPUnit\Framework\TestCase;

/**
 * UIContext::setTextScale(): the immediate-mode widgets follow the player's text
 * size. NullRenderer2D measures 0.6 * size per byte.
 */
final class UIContextTextScaleTest extends TestCase
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    private UIStyle $style;
    private UIContext $ui;

    protected function setUp(): void
    {
        $test = $this;
        $renderer = new class(1280, 720, $test) extends NullRenderer2D {
            public function __construct(int $w, int $h, private readonly UIContextTextScaleTest $test)
            {
                parent::__construct($w, $h);
            }

            public function drawText(string $text, float $x, float $y, float $size, Color $color): void
            {
                $this->test->calls[] = ['text', [$text, $x, $y, $size]];
            }

            public function drawTextCentered(string $text, float $cx, float $cy, float $size, Color $color): void
            {
                $this->test->calls[] = ['centered', [$text, $cx, $cy, $size]];
            }

            public function drawRoundedRect(float $x, float $y, float $w, float $h, float $radius, Color $color): void
            {
                $this->test->calls[] = ['rrect', [$x, $y, $w, $h]];
            }

            public function drawRectOutline(float $x, float $y, float $w, float $h, Color $color, float $lineWidth = 1.0): void
            {
                $this->test->calls[] = ['outline', [$x, $y, $w, $h]];
            }

            public function drawRect(float $x, float $y, float $w, float $h, Color $color): void
            {
                $this->test->calls[] = ['rect', [$x, $y, $w, $h]];
            }
        };
        $this->style = new UIStyle(fontSize: 10.0, padding: 5.0, buttonPaddingH: 10.0);
        $this->ui = new UIContext($renderer, new Input(), $this->style);
    }

    /** One of everything, vertical and horizontal. */
    private function scene(UIContext $ui): void
    {
        $ui->beginFrame();
        $ui->begin(0.0, 0.0, 300.0);
        $ui->label('Header');
        $ui->button('a', 'Hire');
        $ui->button('b', 'Fixed', 120.0);
        $ui->checkbox('c', 'Option', true);
        $ui->slider('d', 'Volume', 0.5);
        $ui->textField('e', 'Name', 'abc');
        $ui->dropdown('f', ['One', 'Two'], 0);
        $ui->progressBar('Load', 0.5);
        $ui->labelWrapped('Some wrapped text');
        $ui->begin(0.0, 200.0, 300.0, 'horizontal');
        $ui->button('g', 'Left');
        $ui->button('h', 'Right');
        $ui->end();
        $ui->end();
        $ui->flushOverlays();
    }

    /** @return list<array{0: string, 1: array<int, mixed>}> */
    private function record(UIContext $ui): array
    {
        $this->calls = [];
        $this->scene($ui);

        return $this->calls;
    }

    public function testIdentityKeepsTheSameStyleObjectAndTheSameDrawCalls(): void
    {
        $untouched = $this->record($this->ui);
        $this->assertNotSame([], $untouched);

        $this->ui->setTextScale(1.0);
        $this->assertSame($this->style, $this->ui->getStyle());
        $this->assertSame($untouched, $this->record($this->ui));

        // Back from a scale to 1.0 is the same path again.
        $this->ui->setTextScale(1.5);
        $this->ui->setTextScale(1.0);
        $this->assertSame($this->style, $this->ui->getStyle());
        $this->assertSame($untouched, $this->record($this->ui));
    }

    public function testDefaultsAndReaders(): void
    {
        $this->assertSame(1.0, $this->ui->getTextScale());
        $this->assertSame(1.0, $this->ui->textScale()->factor);
        $this->assertSame($this->style, $this->ui->getBaseStyle());
    }

    public function testTheFactorIsClampedLikeAccessibilitySettings(): void
    {
        $this->ui->setTextScale(0.1);
        $this->assertSame(0.75, $this->ui->getTextScale());

        $this->ui->setTextScale(9.0);
        $this->assertSame(3.0, $this->ui->getTextScale());
        $this->assertSame(30.0, $this->ui->getStyle()->fontSize);
        $this->assertSame(10.0, $this->ui->getBaseStyle()->fontSize);
    }

    public function testTextSizesAndRowHeightsScale(): void
    {
        $this->ui->setTextScale(1.25);
        $this->ui->begin(0.0, 0.0, 300.0);
        $this->ui->label('Header');

        $this->assertSame(['text', ['Header', 5.0, 5.0, 12.5]], $this->calls[0]);
        $this->assertSame(12.5 + 10.0, $this->ui->rowHeight());
        $this->assertSame(22.5, $this->ui->getCursorY() - 0.0 - $this->style->itemSpacing);
    }

    public function testAutoSizedHorizontalButtonsGrowWithTheirText(): void
    {
        $widthAt = function (float $factor): float {
            $this->setUp();
            $this->ui->setTextScale($factor);
            $this->calls = [];
            $this->ui->begin(0.0, 0.0, 300.0, 'horizontal');
            $this->ui->button('g', 'Left');
            foreach ($this->calls as [$kind, $args]) {
                if ($kind === 'rrect') {
                    return $args[2];
                }
            }
            $this->fail('no button drawn');
        };

        $w100 = $widthAt(1.0);
        $w125 = $widthAt(1.25);
        $w150 = $widthAt(1.5);

        $this->assertSame(4 * 10.0 * 0.55 + 20.0, $w100);
        $this->assertSame(4 * 12.5 * 0.55 + 20.0, $w125);
        $this->assertSame(4 * 15.0 * 0.55 + 20.0, $w150);
    }

    public function testButtonHeightFollowsTheScaledFont(): void
    {
        $this->ui->setTextScale(1.5);
        $this->ui->begin(0.0, 0.0, 300.0);
        $this->ui->button('a', 'Hire');

        $heights = array_values(array_map(static fn (array $c): float => $c[1][3], array_filter($this->calls, static fn (array $c): bool => $c[0] === 'rrect')));
        $this->assertSame(15.0 + 10.0, $heights[0]);
    }

    public function testFixedWidthButtonKeepsItsWidthAndShrinksTheTextToFit(): void
    {
        // 'Fixed Width Label' is 17 bytes: 0.6 * 10 * 17 = 102 at design size, 153 at 1.5.
        $this->ui->setTextScale(1.5);
        $this->ui->begin(0.0, 0.0, 300.0);
        $this->ui->button('a', 'Fixed Width Label', 140.0);

        $rect = array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'rrect'))[0][1];
        $text = array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'centered'))[0][1];
        $this->assertSame(140.0, $rect[2], 'the width stays what the caller asked for');
        // Room is 140 - 20 padding = 120; fitted size = 15 * 120 / 153.
        $this->assertEqualsWithDelta(15.0 * 120.0 / 153.0, $text[3], 1.0e-9);
        $this->assertGreaterThan(10.0, $text[3]);
        $this->assertLessThan(15.0, $text[3]);
    }

    public function testShrinkToFitNeverGoesBelowTheDesignSize(): void
    {
        $this->ui->setTextScale(2.0);
        $this->ui->begin(0.0, 0.0, 300.0);
        $this->ui->button('a', 'Far too long for this little button', 60.0);

        $text = array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'centered'))[0][1];
        $this->assertSame(10.0, $text[3], 'floor: the unscaled size');
    }

    public function testTextThatFitsIsNotShrunk(): void
    {
        $this->ui->setTextScale(1.5);
        $this->ui->begin(0.0, 0.0, 300.0);
        $this->ui->button('a', 'OK', 200.0);

        $text = array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'centered'))[0][1];
        $this->assertSame(15.0, $text[3]);
    }

    public function testDropdownTextShrinksToFitItsFixedWidth(): void
    {
        $this->ui->setTextScale(1.5);
        $this->ui->begin(0.0, 0.0, 300.0);
        $this->ui->dropdown('f', ['A rather long option'], 0, 100.0);

        $texts = array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'text' && $c[1][0] === 'A rather long option'));
        $this->assertLessThan(15.0, $texts[0][1][3]);
        $this->assertGreaterThanOrEqual(10.0, $texts[0][1][3]);
    }

    public function testSetStyleKeepsTheScaleOnTheNewStyle(): void
    {
        $this->ui->setTextScale(1.5);
        $next = new UIStyle(fontSize: 20.0);

        $this->ui->setStyle($next);

        $this->assertSame($next, $this->ui->getBaseStyle());
        $this->assertSame(30.0, $this->ui->getStyle()->fontSize);
    }

    public function testTextScaleHelperAgreesWithTheContext(): void
    {
        $this->ui->setTextScale(1.25);

        $scale = $this->ui->textScale();

        $this->assertInstanceOf(TextScale::class, $scale);
        $this->assertSame($this->ui->getStyle()->fontSize, $scale->font($this->style->fontSize));
    }
}

<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Math\Mat3;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Rendering\ScaledRenderer2D;
use PHPolygon\Runtime\Input;
use PHPolygon\Runtime\ScaledInput;
use PHPolygon\UI\UIContext;
use PHPolygon\UI\UIScale;
use PHPUnit\Framework\TestCase;

final class ScaledRenderer2DTest extends TestCase
{
    /** @return NullRenderer2D&object{calls: list<array{0: string, 1: list<mixed>}>} */
    private function recorder(int $w = 3840, int $h = 2160): NullRenderer2D
    {
        return new class($w, $h) extends NullRenderer2D {
            /** @var list<array{0: string, 1: list<mixed>}> */
            public array $calls = [];

            public function drawRect(float $x, float $y, float $w, float $h, Color $color): void
            {
                $this->calls[] = ['rect', [$x, $y, $w, $h]];
            }

            public function drawRoundedRectOutline(float $x, float $y, float $w, float $h, float $radius, Color $color, float $lineWidth = 1.0): void
            {
                $this->calls[] = ['outline', [$x, $y, $w, $h, $radius, $lineWidth]];
            }

            public function drawText(string $text, float $x, float $y, float $size, Color $color): void
            {
                $this->calls[] = ['text', [$text, $x, $y, $size]];
            }

            public function drawTextCentered(string $text, float $cx, float $cy, float $size, Color $color): void
            {
                $this->calls[] = ['text', [$text, $cx, $cy, $size]];
            }

            public function drawLine(Vec2 $from, Vec2 $to, Color $color, float $width = 1.0): void
            {
                $this->calls[] = ['line', [$from->x, $from->y, $to->x, $to->y, $width]];
            }

            public function pushScissor(float $x, float $y, float $w, float $h): void
            {
                $this->calls[] = ['scissor', [$x, $y, $w, $h]];
            }

            public function pushTransform(Mat3 $matrix): void
            {
                $this->calls[] = ['transform', [$matrix]];
            }
        };
    }

    public function testFactorOnePassesEverythingThrough(): void
    {
        $inner = $this->recorder(1920, 1080);
        $r = new ScaledRenderer2D($inner, new UIScale(1.0));

        $r->drawRect(1.3, 2.7, 10.1, 5.5, new Color(1, 1, 1));
        $r->drawText('a', 4.5, 6.5, 16.0, new Color(1, 1, 1));

        self::assertSame(1920, $r->getWidth());
        self::assertSame([['rect', [1.3, 2.7, 10.1, 5.5]], ['text', ['a', 4.5, 6.5, 16.0]]], $inner->calls);
    }

    public function testDrawsScaledAndReportsLogicalSize(): void
    {
        $inner = $this->recorder(3840, 2160);
        $r = new ScaledRenderer2D($inner, new UIScale(2.0));

        $r->drawRect(10, 20, 30, 1, new Color(1, 1, 1));
        $r->drawText('Hallo', 5, 6, 16.0, new Color(1, 1, 1));
        $r->drawRoundedRectOutline(0, 0, 100, 50, 3.0, new Color(1, 1, 1), 1.5);
        $r->drawLine(new Vec2(1, 2), new Vec2(3, 4), new Color(1, 1, 1));

        self::assertSame(1920, $r->getWidth());
        self::assertSame(1080, $r->getHeight());
        self::assertSame(['rect', [20.0, 40.0, 60.0, 2.0]], $inner->calls[0]);
        // Text is asked for at the larger size - never a magnified atlas.
        self::assertSame(['text', ['Hallo', 10.0, 12.0, 32.0]], $inner->calls[1]);
        self::assertSame(['outline', [0.0, 0.0, 200.0, 100.0, 6.0, 3.0]], $inner->calls[2]);
        self::assertSame(['line', [2.0, 4.0, 6.0, 8.0, 2.0]], $inner->calls[3]);
    }

    public function testMeasurementsComeBackInLogicalPixels(): void
    {
        $r = new ScaledRenderer2D(new NullRenderer2D(3840, 2160), new UIScale(2.0));
        $plain = (new NullRenderer2D())->measureText('Code', 16.0);

        $m = $r->measureText('Code', 16.0);

        self::assertEqualsWithDelta($plain->width, $m->width, 1e-9);
        self::assertEqualsWithDelta($plain->height, $m->height, 1e-9);
    }

    public function testFractionalFactorSnapsRectEdgesToWholePixels(): void
    {
        $inner = $this->recorder(2560, 1440);
        $r = new ScaledRenderer2D($inner, new UIScale(1.375));

        // Two neighbours and a hairline.
        $r->drawRect(0, 0, 7, 10, new Color(1, 1, 1));
        $r->drawRect(7, 0, 7, 10, new Color(1, 1, 1));
        $r->drawRect(3, 5, 20, 1, new Color(1, 1, 1));

        [, [$x0, , $w0]] = $inner->calls[0];
        [, [$x1]] = $inner->calls[1];
        [, [, $hy, , $hh]] = $inner->calls[2];
        foreach ([$x0, $w0, $x1, $hy, $hh] as $v) {
            self::assertSame(round($v), $v, 'edge not on a whole pixel');
        }
        self::assertSame($x0 + $w0, $x1, 'neighbours must share their edge');
        self::assertGreaterThanOrEqual(1.0, $hh, 'a hairline must not vanish');
    }

    public function testScissorAndTransformLiveInLogicalPixels(): void
    {
        $inner = $this->recorder();
        $r = new ScaledRenderer2D($inner, new UIScale(2.0));

        $r->pushScissor(10, 10, 50, 20);
        $r->pushTransform(Mat3::translation(5, 7));

        self::assertSame(['scissor', [20.0, 20.0, 100.0, 40.0]], $inner->calls[0]);
        /** @var Mat3 $m */
        $m = $inner->calls[1][1][0];
        // A logical translation of (5, 7) moves the scaled drawing by (10, 14).
        $p = $m->transformPoint(new Vec2(20, 20));
        self::assertEqualsWithDelta(30.0, $p->x, 1e-9);
        self::assertEqualsWithDelta(34.0, $p->y, 1e-9);
    }

    public function testUiContextLaysOutAndHitTestsInLogicalPixels(): void
    {
        $scale = new UIScale(2.0);
        $inner = $this->recorder();
        $input = new Input();
        $ui = new UIContext(new NullRenderer2D(3840, 2160), $input);
        $renderer = new ScaledRenderer2D($inner, $scale);
        $scaledInput = new ScaledInput($input, $scale);

        // Physical (220, 120) = logical (110, 60): inside a button at (100, 50).
        $input->handleCursorPosEvent(220.0, 120.0);
        [$r0, $i0] = $ui->useBackends($renderer, $scaledInput);
        try {
            $ui->begin(100.0, 50.0, 200.0);
            $ui->button('b', 'OK', 200.0);
            $ui->end();
            $hovered = $ui->isAnyHovered();
        } finally {
            $ui->useBackends($r0, $i0);
        }

        self::assertTrue($hovered, 'the pointer must be compared in logical pixels');
        $texts = array_values(array_filter($inner->calls, static fn (array $c): bool => $c[0] === 'text'));
        self::assertNotEmpty($texts, 'the button label drew through the scaled renderer');
        // Label inside the doubled button rect (x 200..600 physical).
        self::assertGreaterThanOrEqual(200.0, $texts[0][1][1]);
        self::assertGreaterThan(16.0, $texts[0][1][3], 'label font size is scaled');
    }
}

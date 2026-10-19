<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\Color;
use PHPUnit\Framework\TestCase;

final class ColorSrgbTest extends TestCase
{
    public function testSrgbHexDecodesToLinear(): void
    {
        $c = Color::srgb('#808080');
        // sRGB 128/255 is linear ~0.2158 (the textbook mid-grey value).
        self::assertEqualsWithDelta(0.21586, $c->r, 1e-4);
        self::assertEqualsWithDelta($c->r, $c->g, 1e-12);
        self::assertSame(1.0, $c->a);

        self::assertSame(0.0, Color::srgb('#000000')->r);
        self::assertEqualsWithDelta(1.0, Color::srgb('#FFFFFF')->b, 1e-12);
    }

    public function testTheLowEndIsTheLinearSegment(): void
    {
        // Below 0.04045 the sRGB curve is a straight line of slope 1/12.92.
        $c = Color::hex('#0A0A0A');
        self::assertEqualsWithDelta($c->r / 12.92, $c->toLinear()->r, 1e-12);
    }

    public function testAlphaIsNotConverted(): void
    {
        self::assertEqualsWithDelta(128 / 255, Color::srgb('#FFFFFF80')->a, 1e-12);
        self::assertSame(0.3, (new Color(0.5, 0.5, 0.5, 0.3))->toLinear()->toSrgb()->a);
    }

    public function testRoundTrip(): void
    {
        foreach ([0.0, 0.002, 0.04, 0.2, 0.5, 0.73, 1.0] as $v) {
            $c = new Color($v, $v, $v);
            self::assertEqualsWithDelta($v, $c->toLinear()->toSrgb()->r, 1e-9);
            self::assertEqualsWithDelta($v, $c->toSrgb()->toLinear()->g, 1e-9);
        }
    }

    public function testHexStaysAsItWas(): void
    {
        self::assertEqualsWithDelta(128 / 255, Color::hex('#808080')->r, 1e-12);
    }
}

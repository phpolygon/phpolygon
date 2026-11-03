<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Accessibility;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Accessibility\AccessibilitySettings;
use PHPolygon\Rendering\Accessibility\ColorVision;
use PHPolygon\Rendering\Accessibility\ColorVisionFilter;
use PHPolygon\Rendering\Accessibility\ColorVisionMode;
use PHPolygon\Rendering\Color;

final class ColorVisionFilterTest extends TestCase
{
    public function testOffOrZeroStrengthIsNoFilter(): void
    {
        self::assertNull(ColorVisionFilter::fromSettings(new AccessibilitySettings()));
        self::assertNull(ColorVisionFilter::fromSettings(new AccessibilitySettings(colorVision: ColorVisionMode::Protanopia, colorVisionStrength: 0.0)));
        $f = ColorVisionFilter::fromSettings(new AccessibilitySettings(colorVision: ColorVisionMode::Tritanopia, colorVisionStrength: 0.5, colorVisionSimulate: true));
        self::assertNotNull($f);
        self::assertSame(ColorVisionMode::Tritanopia, $f->mode);
        self::assertSame(0.5, $f->strength);
        self::assertTrue($f->simulate);
        self::assertTrue($f->sameAs(new ColorVisionFilter(ColorVisionMode::Tritanopia, 0.5, true)));
        self::assertFalse($f->sameAs(new ColorVisionFilter(ColorVisionMode::Tritanopia, 0.5)));
        self::assertFalse($f->sameAs(null));
    }

    /** The packed form is ColorVision::correct() on the decoded colour, alpha untouched. */
    public function testPackedColourIsTheCorrectedColourWithItsAlpha(): void
    {
        $f = new ColorVisionFilter(ColorVisionMode::Deuteranopia);
        $argb = (0x80 << 24) | (217 << 16) | (51 << 8) | 51;
        $out = $f->argb($argb);
        self::assertSame(0x80, ($out >> 24) & 0xFF);
        $expected = ColorVision::encode(ColorVision::correct(ColorVisionMode::Deuteranopia, ColorVision::decode([217 / 255, 51 / 255, 51 / 255])));
        self::assertEqualsWithDelta(min(1.0, $expected[0]) * 255, ($out >> 16) & 0xFF, 0.5);
        self::assertEqualsWithDelta(min(1.0, $expected[1]) * 255, ($out >> 8) & 0xFF, 0.5);
        self::assertEqualsWithDelta(min(1.0, $expected[2]) * 255, $out & 0xFF, 0.5);
        self::assertSame($out, $f->argb($argb), 'the cached answer is the same');
        self::assertNotSame($argb, $out);
    }

    public function testGreysStayGrey(): void
    {
        foreach ([ColorVisionMode::Protanopia, ColorVisionMode::Deuteranopia, ColorVisionMode::Tritanopia] as $mode) {
            $f = new ColorVisionFilter($mode);
            foreach ([0x00, 0x40, 0x80, 0xC0, 0xFF] as $v) {
                $argb = (0xFF << 24) | ($v << 16) | ($v << 8) | $v;
                self::assertSame($argb, $f->argb($argb), sprintf('%s grey %02x', $mode->value, $v));
            }
        }
    }

    /** Status red and green as a deuteranope sees them: far apart only after the correction. */
    public function testCorrectionSeparatesRedAndGreenForTheViewer(): void
    {
        $f = new ColorVisionFilter(ColorVisionMode::Deuteranopia);
        $seen = static function (Color $c): array {
            return ColorVision::simulate(ColorVisionMode::Deuteranopia, ColorVision::decode([$c->r, $c->g, $c->b]));
        };
        $red = new Color(0.85, 0.2, 0.2);
        $green = new Color(0.2, 0.65, 0.2);
        $before = ColorVision::deltaE($seen($red), $seen($green));
        $after = ColorVision::deltaE($seen($f->color($red)), $seen($f->color($green)));
        self::assertGreaterThan($before * 3.0, $after, sprintf('dE %.1f -> %.1f', $before, $after));
        self::assertSame(0.4, $f->color(new Color(0.85, 0.2, 0.2, 0.4))->a);
    }

    public function testCacheStartsOverAtItsLimit(): void
    {
        $f = new ColorVisionFilter(ColorVisionMode::Protanopia);
        $first = $f->argb(0xFF102030);
        for ($i = 0; $i < ColorVisionFilter::CACHE_LIMIT + 10; $i++) {
            $f->argb(0xFF000000 | $i * 37);
        }
        self::assertSame($first, $f->argb(0xFF102030));
    }
}

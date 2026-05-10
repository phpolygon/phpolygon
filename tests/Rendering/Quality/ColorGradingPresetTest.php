<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPolygon\Rendering\Quality\ColorGradingPreset;
use PHPUnit\Framework\TestCase;

final class ColorGradingPresetTest extends TestCase
{
    public function testNeutralIsIdentity(): void
    {
        $p = ColorGradingPreset::Neutral->params();
        $this->assertSame([0.0, 0.0, 0.0], $p['lift']);
        $this->assertSame([1.0, 1.0, 1.0], $p['gamma']);
        $this->assertSame([1.0, 1.0, 1.0], $p['gain']);
        $this->assertSame(1.0, $p['saturation']);
    }

    public function testEveryPresetReturnsAllFourFields(): void
    {
        foreach (ColorGradingPreset::cases() as $preset) {
            $p = $preset->params();
            $this->assertArrayHasKey('lift', $p);
            $this->assertArrayHasKey('gamma', $p);
            $this->assertArrayHasKey('gain', $p);
            $this->assertArrayHasKey('saturation', $p);
            $this->assertCount(3, $p['lift']);
            $this->assertCount(3, $p['gamma']);
            $this->assertCount(3, $p['gain']);
        }
    }

    public function testWarmPushesRedAndCoolPushesBlue(): void
    {
        $w = ColorGradingPreset::Warm->params();
        $c = ColorGradingPreset::Cool->params();
        // Warm: red gain > blue gain; Cool: blue gain > red gain.
        $this->assertGreaterThan($w['gain'][2], $w['gain'][0]);
        $this->assertGreaterThan($c['gain'][0], $c['gain'][2]);
    }

    public function testMutedDesaturates(): void
    {
        $this->assertLessThan(1.0, ColorGradingPreset::Muted->params()['saturation']);
    }
}

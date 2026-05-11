<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetWind;
use PHPolygon\Rendering\Material;
use PHPUnit\Framework\TestCase;

final class ClothMaterialTest extends TestCase
{
    public function testClothFlagDefaultsOff(): void
    {
        $m = Material::default();
        $this->assertFalse($m->cloth);
        $this->assertSame(0.05, $m->clothStrength);
        $this->assertSame(1.0,  $m->clothFrequency);
        $this->assertSame(0.0,  $m->clothPhase);
        $this->assertTrue($m->clothAnchorTop);
    }

    public function testClothFactoryEnablesAndKeepsAnchorTop(): void
    {
        $m = Material::cloth(albedo: Color::white());
        $this->assertTrue($m->cloth);
        $this->assertTrue($m->clothAnchorTop);
        // Sensible default sway for a Cyberpunk trenchcoat
        $this->assertGreaterThan(0.0, $m->clothStrength);
        $this->assertGreaterThan(0.0, $m->clothFrequency);
    }

    public function testClothFactoryAcceptsAnchorBottom(): void
    {
        $m = Material::cloth(albedo: Color::red(), anchorTop: false);
        $this->assertFalse($m->clothAnchorTop);
    }

    public function testClothFactoryAcceptsCustomTuning(): void
    {
        $m = Material::cloth(
            albedo: Color::white(),
            strength: 0.2,
            frequency: 2.5,
            phase: 1.3,
        );
        $this->assertSame(0.2, $m->clothStrength);
        $this->assertSame(2.5, $m->clothFrequency);
        $this->assertSame(1.3, $m->clothPhase);
    }

    public function testSetWindDefaultsAreCalmForwardWind(): void
    {
        $cmd = new SetWind();
        $this->assertEqualsWithDelta(0.0, $cmd->direction->x, 1e-6);
        $this->assertEqualsWithDelta(0.0, $cmd->direction->y, 1e-6);
        $this->assertEqualsWithDelta(1.0, $cmd->direction->z, 1e-6);
        $this->assertEqualsWithDelta(0.5, $cmd->intensity, 1e-6);
    }

    public function testSetWindStoresDirectionAndIntensity(): void
    {
        $cmd = new SetWind(new Vec3(1.0, 0.0, 0.5), 0.8);
        $this->assertEqualsWithDelta(1.0, $cmd->direction->x, 1e-6);
        $this->assertEqualsWithDelta(0.5, $cmd->direction->z, 1e-6);
        $this->assertEqualsWithDelta(0.8, $cmd->intensity, 1e-6);
    }
}

<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Command;

use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetEnvironmentMap;
use PHPolygon\Rendering\Command\SetFieldtracingProbes;
use PHPolygon\Rendering\Command\SetGroundWetness;
use PHPolygon\Rendering\Command\SetSkybox;
use PHPolygon\Rendering\Command\SetSkyColors;
use PHPolygon\Rendering\Command\SetSnowCover;
use PHPolygon\Rendering\Command\SetWaveAnimation;
use PHPUnit\Framework\TestCase;

class EnvironmentCommandsTest extends TestCase
{
    public function testSetSkyboxStoresCubemapId(): void
    {
        $cmd = new SetSkybox('night_sky');
        $this->assertSame('night_sky', $cmd->cubemapId);
    }

    public function testSetEnvironmentMapStoresTextureId(): void
    {
        $cmd = new SetEnvironmentMap(42);
        $this->assertSame(42, $cmd->textureId);
    }

    public function testSetSkyColorsStoresBothColors(): void
    {
        $sky = new Color(0.1, 0.2, 0.3, 1.0);
        $horizon = new Color(0.4, 0.5, 0.6, 0.7);
        $cmd = new SetSkyColors($sky, $horizon);

        $this->assertSame($sky, $cmd->skyColor);
        $this->assertSame($horizon, $cmd->horizonColor);
        $this->assertSame(0.1, $cmd->skyColor->r);
        $this->assertSame(0.6, $cmd->horizonColor->b);
    }

    public function testSetSnowCoverStoresCover(): void
    {
        $cmd = new SetSnowCover(0.75);
        $this->assertSame(0.75, $cmd->cover);
    }

    public function testSetSnowCoverDefaultsToZero(): void
    {
        $cmd = new SetSnowCover();
        $this->assertSame(0.0, $cmd->cover);
    }

    public function testSetGroundWetnessStoresWetness(): void
    {
        $cmd = new SetGroundWetness(0.42);
        $this->assertSame(0.42, $cmd->rainWetness);
    }

    public function testSetWaveAnimationStoresAllValues(): void
    {
        $cmd = new SetWaveAnimation(true, 1.5, 2.5, 0.25);
        $this->assertTrue($cmd->enabled);
        $this->assertSame(1.5, $cmd->amplitude);
        $this->assertSame(2.5, $cmd->frequency);
        $this->assertSame(0.25, $cmd->phase);
    }

    public function testSetWaveAnimationDefaults(): void
    {
        $cmd = new SetWaveAnimation();
        $this->assertFalse($cmd->enabled);
        $this->assertSame(0.3, $cmd->amplitude);
        $this->assertSame(0.5, $cmd->frequency);
        $this->assertSame(0.0, $cmd->phase);
    }

    public function testSetFieldtracingProbesRoundTrip(): void
    {
        $origin = new Vec3(-10.0, 0.0, 5.0);
        $size = new Vec3(100.0, 50.0, 100.0);
        $cmd = new SetFieldtracingProbes(
            dataR: 'RRRR',
            dataG: 'GGGG',
            dataB: 'BBBB',
            width: 8,
            height: 4,
            depth: 2,
            origin: $origin,
            size: $size,
            range: 3.5,
            version: 7,
        );

        $this->assertSame('RRRR', $cmd->dataR);
        $this->assertSame('GGGG', $cmd->dataG);
        $this->assertSame('BBBB', $cmd->dataB);
        $this->assertSame(8, $cmd->width);
        $this->assertSame(4, $cmd->height);
        $this->assertSame(2, $cmd->depth);
        $this->assertSame($origin, $cmd->origin);
        $this->assertSame($size, $cmd->size);
        $this->assertSame(3.5, $cmd->range);
        $this->assertSame(7, $cmd->version);
    }

    public function testSetFieldtracingProbesVersionDefaultsToZero(): void
    {
        $cmd = new SetFieldtracingProbes(
            'r', 'g', 'b',
            1, 1, 1,
            Vec3::zero(),
            Vec3::one(),
            1.0,
        );
        $this->assertSame(0, $cmd->version);
    }
}

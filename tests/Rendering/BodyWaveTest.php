<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\BodyWave;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;

final class BodyWaveTest extends TestCase
{
    public function testSwimPacksTheFishConvention(): void
    {
        $u = BodyWave::uniforms(BodyWave::swim(0.06, 0.9, 1.4, -0.5, 0.8));

        self::assertSame([0.06, 0.9, 1.4, 0.0], $u['u_body_wave']);
        self::assertSame([2.0, -0.5, 0.8, 0.0], $u['u_body_wave_axis']);
    }

    public function testSwayCirclesFromTheRoot(): void
    {
        $u = BodyWave::uniforms(BodyWave::sway(0.3, 2.5, 0.2, 0.1));

        self::assertSame([0.3, 5.0, 0.2, 0.0], $u['u_body_wave']);
        self::assertSame([1.0, 0.1, 2.5, (float) BodyWave::LATERAL_CIRCLE], $u['u_body_wave_axis']);
    }

    public function testNoWaveUploadsAZeroAmplitude(): void
    {
        self::assertSame(0.0, BodyWave::uniforms(null)['u_body_wave'][0]);
    }

    public function testTheLateralMustDifferFromTheAxis(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BodyWave(0.1, 1.0, 1.0, BodyWave::AXIS_Y, 0.0, 1.0, BodyWave::AXIS_Y);
    }

    public function testMaterialKeepsTheWaveThroughCopiesAndCanDropIt(): void
    {
        $wave = BodyWave::swim();
        $m = (new Material(albedo: new Color(0.5, 0.5, 0.5)))->withBodyWave($wave);

        self::assertSame($wave, $m->bodyWave);
        self::assertSame($wave, $m->withReactive(0.5)->bodyWave, 'other copies keep it');
        self::assertNull($m->withBodyWave(null)->bodyWave);
    }
}

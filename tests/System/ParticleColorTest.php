<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Rendering\Color;
use PHPolygon\System\ParticleColor;
use PHPUnit\Framework\TestCase;

/** Shader plan 7.1: a particle's colour over life, packed RGBA6 in one float. */
final class ParticleColorTest extends TestCase
{
    public function testPackFitsAFloatExactlyAndRoundTrips(): void
    {
        $p = ParticleColor::pack(1.0, 0.5, 0.0, 0.25);
        self::assertLessThan(16777216.0, $p);
        self::assertSame($p, (float) unpack('f', pack('f', $p))[1]);
        [$r, $g, $b, $a] = ParticleColor::unpack($p);
        self::assertEqualsWithDelta(1.0, $r, 1 / 63);
        self::assertEqualsWithDelta(0.5, $g, 1 / 63);
        self::assertEqualsWithDelta(0.0, $b, 1 / 63);
        self::assertEqualsWithDelta(0.25, $a, 1 / 63);
    }

    public function testOffMeansNoStops(): void
    {
        $e = $this->emitter();
        self::assertSame([], ParticleColor::stops($e));
        self::assertSame(0.0, ParticleColor::packedAt([], 0.5));
    }

    public function testStartEndAndGradient(): void
    {
        $e = $this->emitter();
        $e->colorOverLife = true;
        [$r, , , $a] = ParticleColor::unpack(ParticleColor::packedAt(ParticleColor::stops($e), 0.5));
        self::assertEqualsWithDelta(0.5, $r, 1 / 63);
        self::assertEqualsWithDelta(0.5, $a, 1 / 63);

        $e->colorGradient = [[1.0, new Color(0.0, 0.0, 1.0, 0.0)], [0.0, new Color(1.0, 1.0, 1.0, 1.0)], [0.3, new Color(1.0, 0.5, 0.0, 1.0)]];
        $stops = ParticleColor::stops($e);
        self::assertSame([0.0, 0.3, 1.0], array_column($stops, 0));
        [$r, $g, $b] = ParticleColor::unpack(ParticleColor::packedAt($stops, 0.3));
        self::assertEqualsWithDelta(1.0, $r, 1 / 63);
        self::assertEqualsWithDelta(0.5, $g, 1 / 63);
        self::assertEqualsWithDelta(0.0, $b, 1 / 63);
    }

    private function emitter(): ParticleEmitter
    {
        $e = new ParticleEmitter();
        $e->startColor = new Color(1.0, 1.0, 1.0, 1.0);
        $e->endColor = new Color(0.0, 0.0, 0.0, 0.0);
        return $e;
    }
}

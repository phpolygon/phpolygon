<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Rendering\Color;
use PHPolygon\System\GpuParticleBaker;
use PHPolygon\System\ParticleColor;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Every quantised colour survives pack() -> unpack() unchanged; all alpha and red
     * values (red sits in the low bits, alpha in the high ones), green and blue at
     * their ends and the middle.
     */
    public function testEveryQuantisedColourRoundTrips(): void
    {
        foreach (self::quantisedColours() as [$r, $g, $b, $a]) {
            $p = ParticleColor::pack($r / 63, $g / 63, $b / 63, $a / 63);
            self::assertSame([$r, $g, $b, $a], array_map(static fn (float $c): int => (int) round($c * 63), ParticleColor::unpack($p)), "rgba6 {$r} {$g} {$b} {$a}");
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function shaderUnpacks(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            'mesh3d.frag (CPU and GPU particles)' => [(string) file_get_contents($root . '/resources/shaders/source/vio/mesh3d.frag.glsl'), 'v_instance_params.x'],
            'GpuParticleBaker step kernel (colUnpack)' => [GpuParticleBaker::SHADER, 'v'],
        ];
    }

    /**
     * The shaders round the packed float to an integer before they split it. Values
     * from 2^23 (alpha >= 32/63) up have no fraction bits left: adding 0.5 lands
     * exactly between two floats, round-to-nearest-even rounds an odd value up, and
     * red (the low bits) overflowed into green - red turned black, white turned black.
     * The float add is emulated here in float32 (a double add is exact for these
     * values, the one rounding to float32 is the GPU's).
     */
    #[DataProvider('shaderUnpacks')]
    public function testShaderUnpackKeepsEveryPackedValue(string $source, string $operand): void
    {
        $pattern = '/floor\(' . preg_quote($operand, '/') . ' \+ ([0-9.]+)\)/';
        self::assertSame(1, preg_match($pattern, $source, $m), 'the unpack rounding this test models is in the shader');
        $offset = (float) $m[1];

        foreach (self::quantisedColours() as [$r, $g, $b, $a]) {
            $v = ParticleColor::pack($r / 63, $g / 63, $b / 63, $a / 63);
            $pv = floor(self::f32($v + $offset));
            $got = [(int) fmod($pv, 64.0), (int) fmod(floor($pv / 64.0), 64.0), (int) fmod(floor($pv / 4096.0), 64.0), (int) floor($pv / 262144.0)];
            self::assertSame([$r, $g, $b, $a], $got, "rgba6 {$r} {$g} {$b} {$a} (packed {$v})");
        }
    }

    /** @return \Generator<array{0: int, 1: int, 2: int, 3: int}> */
    private static function quantisedColours(): \Generator
    {
        $edges = [0, 1, 31, 32, 62, 63];
        for ($a = 0; $a < 64; $a++) {
            for ($r = 0; $r < 64; $r++) {
                foreach ($edges as $g) {
                    foreach ($edges as $b) {
                        yield [$r, $g, $b, $a];
                    }
                }
            }
        }
    }

    private static function f32(float $v): float
    {
        return (float) unpack('g', pack('g', $v))[1];
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

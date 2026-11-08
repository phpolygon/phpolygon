<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Fieldtracing;

use PHPUnit\Framework\TestCase;
use PHPolygon\Fieldtracing\Bake\BakedLightBaker;
use PHPolygon\Fieldtracing\Bake\SdfVolumeBaker;
use PHPolygon\Fieldtracing\Sdf\BoxSdf;
use PHPolygon\Fieldtracing\Volume\BakedLightVolume;
use PHPolygon\Fieldtracing\Volume\SdfVolume;
use PHPolygon\Math\Vec3;

/**
 * BakedLightBaker: a 2 m cube on open ground - the ground at its foot sees less sky
 * than open ground, the side away from the sun lies in its shadow, the sun side and
 * far ground are lit. Samples inside the cube take their neighbours' light.
 */
final class BakedLightBakerTest extends TestCase
{
    private static ?BakedLightVolume $volume = null;
    private static ?SdfVolume $sdf = null;

    private static function volume(): BakedLightVolume
    {
        if (self::$volume === null) {
            // Cube 2 m, centre (0, 1, 0): it stands on y = 0. The grid spans 12 x 4 x 12 m.
            self::$sdf = SdfVolumeBaker::bake(new BoxSdf(new Vec3(2.0, 2.0, 2.0), new Vec3(0.0, 1.0, 0.0)), new Vec3(-6.0, 0.0, -6.0), new Vec3(6.0, 4.0, 6.0), 25);
            // Sun from +x, 45 degrees up: the shadow falls toward -x.
            self::$volume = BakedLightBaker::bake(self::$sdf, new Vec3(1.0, 1.0, 0.0));
        }
        return self::$volume;
    }

    /** @return array{0: int, 1: int, 2: int} grid index of a world point */
    private static function cell(float $x, float $y, float $z): array
    {
        $v = self::volume();
        return [
            (int) round(($x - $v->origin->x) / $v->cellSize),
            (int) round(($y - $v->origin->y) / $v->cellSize),
            (int) round(($z - $v->origin->z) / $v->cellSize),
        ];
    }

    public function testTheGridMatchesTheSdf(): void
    {
        $v = self::volume();
        self::assertNotNull(self::$sdf);
        self::assertSame([self::$sdf->nx, self::$sdf->ny, self::$sdf->nz], [$v->nx, $v->ny, $v->nz]);
        self::assertEqualsWithDelta(12.0, $v->size()->x, 1e-9);
        self::assertSame($v->nx * $v->ny * $v->nz * 4, strlen($v->toRgba8()));
        self::assertEqualsWithDelta(sqrt(0.5), $v->towardSun->x, 1e-9, 'the sun direction is stored normalised');
    }

    public function testTheFootOfTheCubeSeesLessSky(): void
    {
        $v = self::volume();
        $foot = $v->skyAt(...self::cell(-1.5, 0.5, 0.0));
        $open = $v->skyAt(...self::cell(-5.5, 0.5, 5.5));
        self::assertGreaterThan(0.95, $open, 'open ground sees the whole sky');
        self::assertLessThan($open - 0.15, $foot, sprintf('beside the cube %.2f, open %.2f', $foot, $open));
        $top = $v->skyAt(...self::cell(0.0, 2.5, 0.0));
        self::assertGreaterThan(0.95, $top, 'above the cube the sky is open');
    }

    public function testTheShadowFallsAwayFromTheSun(): void
    {
        $v = self::volume();
        $shadow = $v->sunAt(...self::cell(-1.5, 0.5, 0.0));
        $lit = $v->sunAt(...self::cell(1.5, 0.5, 0.0));
        $far = $v->sunAt(...self::cell(-1.5, 0.5, 4.5));
        self::assertLessThan(0.2, $shadow, 'behind the cube, seen from the sun');
        self::assertGreaterThan(0.95, $lit, 'the sun side is lit');
        self::assertGreaterThan(0.95, $far, 'beside the shadow it is lit');
    }

    public function testSamplesInsideTakeTheirNeighboursLight(): void
    {
        $v = self::volume();
        // Just inside the shadowed face: blended from the dark side, not left at 1.
        $inside = $v->sunAt(...self::cell(-0.5, 0.5, 0.0));
        $outside = $v->sunAt(...self::cell(-1.5, 0.5, 0.0));
        self::assertLessThan(0.5, $inside);
        self::assertEqualsWithDelta($outside, $inside, 0.35);
    }

    public function testASunBelowTheHorizonLightsNothing(): void
    {
        self::volume();
        self::assertNotNull(self::$sdf);
        $night = BakedLightBaker::bake(self::$sdf, new Vec3(0.0, -1.0, 0.0), 2.0, 4.0);
        self::assertSame(0.0, $night->sunAt(...self::cell(-5.5, 0.5, 5.5)));
    }
}

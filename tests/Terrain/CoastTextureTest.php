<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Terrain;

use PHPolygon\Terrain\CoastField;
use PHPolygon\Terrain\CoastTexture;
use PHPUnit\Framework\TestCase;

final class CoastTextureTest extends TestCase
{
    /** A cone island: waterline at r = 10, seabed falling 0.5 m per metre beyond it. */
    private static function ground(float $x, float $z): float
    {
        return 10.0 - hypot($x, $z);
    }

    private function baked(float $offsetX = 0.0): CoastTexture
    {
        $field = CoastField::waterline(static fn (float $x, float $z): float => self::ground($x, $z), 0.0, -40.0, 40.0, -40.0, 40.0, 1.0);

        return (new CoastTexture(-60.0 + $offsetX, -60.0, 60.0 + $offsetX, 60.0, 120, 120, 8.0, 24.0, 16.0))
            ->addField($field, $offsetX)
            ->bake(static fn (float $x, float $z): float => max(0.0, -self::ground($x - $offsetX, $z)));
    }

    public function testDistanceAndDepthDecodeToWhatTheCoastSays(): void
    {
        $t = $this->baked();

        $sea = $t->sample(18.0, 0.0);
        self::assertEqualsWithDelta(8.0, $sea['distance'], 1.2);
        self::assertEqualsWithDelta(8.0, $sea['depth'], 0.3);
        self::assertSame(1.0, $sea['coverage']);

        $land = $t->sample(6.0, 0.0);
        self::assertEqualsWithDelta(-4.0, $land['distance'], 1.2);
        self::assertSame(0.0, $land['depth']);
    }

    public function testRangesClampTheEncoding(): void
    {
        $t = $this->baked();

        // 30 m out lies beyond the 24 m sea range, the middle beyond the 8 m land range.
        self::assertEqualsWithDelta(24.0, $t->sample(35.0, 0.0)['distance'], 0.01);
        self::assertEqualsWithDelta(-8.0, $t->sample(0.0, 0.0)['distance'], 0.01);
        // Depth saturates at maxDepth.
        self::assertEqualsWithDelta(16.0, $t->sample(35.0, 0.0)['depth'], 0.01);
    }

    public function testNoRasterMeansFarDeepWater(): void
    {
        $t = $this->baked();

        $edge = $t->sample(55.0, 55.0);
        self::assertSame(0.0, $edge['coverage']);
        self::assertEqualsWithDelta(24.0, $edge['distance'], 0.01);
    }

    public function testAFieldBuiltAroundItsOwnOriginLandsAtItsOffset(): void
    {
        $t = $this->baked(500.0);

        self::assertEqualsWithDelta(8.0, $t->sample(518.0, 0.0)['distance'], 1.2);
        self::assertLessThan(0.0, $t->sample(500.0, 0.0)['distance']);
    }

    public function testRowZeroIsTheMinimumZ(): void
    {
        $field = CoastField::waterline(
            // Land only in the south half (z < 0).
            static fn (float $x, float $z): ?float => $z < 0.0 ? 1.0 : null,
            0.0,
            -20.0,
            20.0,
            -20.0,
            20.0,
            1.0,
        );
        $t = (new CoastTexture(-20.0, -20.0, 20.0, 20.0, 8, 8))->addField($field)->bake();
        $rgba = $t->rgba();

        // Row 0 (z = -17.5) is land: R below the waterline value 16 / 64.
        self::assertLessThan(64, ord($rgba[0]));
        // The last row (z = +17.5) is open water.
        self::assertGreaterThan(64, ord($rgba[7 * 8 * 4]));
    }

    public function testSmoothingRoundsOffTheStepsOfACoarseRaster(): void
    {
        // A straight shore at a shallow angle to the grid: land where z < 0.2 x.
        $field = CoastField::waterline(static fn (float $x, float $z): float => $z < 0.2 * $x ? 1.0 : -1.0, 0.0, -40.0, 40.0, -40.0, 40.0, 2.0);
        $len = sqrt(1.04);
        $error = static function (int $smooth) use ($field, $len): float {
            $t = (new CoastTexture(-40.0, -40.0, 40.0, 40.0, 80, 80, 16.0, 16.0, 16.0))->addField($field)->bake(null, $smooth);
            $worst = 0.0;
            for ($x = -20.0; $x <= 20.0; $x += 0.5) {
                // 3 m out to sea along the shore normal (-0.2, 1) / |.|.
                $worst = max($worst, abs($t->sample($x - 0.6 / $len, 0.2 * $x + 3.0 / $len)['distance'] - 3.0));
            }

            return $worst;
        };

        self::assertLessThan($error(0), $error(2));
        // What stays is the bias of a 2 m raster (distances to cell centres), not steps.
        self::assertLessThan(1.5, $error(2));
    }

    public function testSaveAndLoadKeepTheTexels(): void
    {
        $t = $this->baked();
        $path = sys_get_temp_dir() . '/phpolygon-coast-' . getmypid() . '.bin';
        try {
            $t->save($path);
            $back = CoastTexture::load($path);
        } finally {
            @unlink($path);
        }

        self::assertSame($t->rgba(), $back->rgba());
        self::assertSame($t->mapUniform(), $back->mapUniform());
        self::assertSame($t->rangeUniform(), $back->rangeUniform());
    }

    public function testTheMapUniformTakesWorldXzToUv(): void
    {
        $t = $this->baked();
        [$minX, $minZ, $invX, $invZ] = $t->mapUniform();

        self::assertEqualsWithDelta(0.5, (0.0 - $minX) * $invX, 1e-9);
        self::assertEqualsWithDelta(1.0, (60.0 - $minZ) * $invZ, 1e-9);
    }
}

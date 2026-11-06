<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Terrain;

use PHPolygon\Terrain\CoastField;
use PHPUnit\Framework\TestCase;

final class CoastFieldTest extends TestCase
{
    /** A 40 m square island with a 10 m crater in the middle. */
    private function field(): CoastField
    {
        $ground = static function (float $x, float $z): ?float {
            if (abs($x) > 20.0 || abs($z) > 20.0) {
                return null;
            }

            return abs($x) <= 5.0 && abs($z) <= 5.0 ? null : -1.5;
        };

        return new CoastField($ground, -50.0, 50.0, -50.0, 50.0, 1.0);
    }

    public function testDistanceIsPositiveInOpenWaterAndNegativeOnLand(): void
    {
        $field = $this->field();

        $sea = $field->at(30.0, 0.0);
        self::assertNotNull($sea);
        self::assertEqualsWithDelta(10.0, $sea['distance'], 1.01);
        self::assertSame(-1.5, $sea['rim']);

        $land = $field->at(15.0, 0.0);
        self::assertNotNull($land);
        self::assertLessThan(0.0, $land['distance']);
        self::assertFalse($land['hole']);
    }

    public function testACraterIsNotTheSea(): void
    {
        $hole = $this->field()->at(0.0, 0.0);

        self::assertNotNull($hole);
        self::assertTrue($hole['hole']);
        self::assertLessThan(0.0, $hole['distance']);
        self::assertNull($hole['rim']);
    }

    public function testTheWaterlineIsWhereTheGroundLeavesTheWater(): void
    {
        // A cone: 10 m high in the middle, falling 1 m per metre, running on under the
        // water down to -20 m (the skirt of an imported island).
        $ground = static fn (float $x, float $z): float => max(-20.0, 10.0 - hypot($x, $z));

        $shell = new CoastField($ground, -40.0, 40.0, -40.0, 40.0, 1.0);
        $waterline = CoastField::waterline($ground, 0.0, -40.0, 40.0, -40.0, 40.0, 1.0);

        // 15 m out is open water 5 m from the waterline at r = 10 ...
        $here = $waterline->at(15.0, 0.0);
        self::assertNotNull($here);
        self::assertEqualsWithDelta(5.0, $here['distance'], 1.01);
        // ... but land for the raw shell, whose rim lies at the raster edge.
        $raw = $shell->at(15.0, 0.0);
        self::assertNotNull($raw);
        self::assertLessThan(0.0, $raw['distance']);
        // Inland of the waterline the distance is negative.
        self::assertLessThan(0.0, $waterline->at(5.0, 0.0)['distance'] ?? 0.0);
    }

    public function testOutsideTheRasterIsUnknown(): void
    {
        self::assertNull($this->field()->at(60.0, 0.0));
    }

    public function testTheShorelinePointsIntoTheWater(): void
    {
        $points = $this->field()->rimPoints();

        self::assertNotEmpty($points);
        foreach ($points as $point) {
            self::assertTrue(abs($point['x']) === 20.0 || abs($point['z']) === 20.0, 'no crater cell on the shoreline');
            self::assertSame(-1.5, $point['y']);
            $outward = $point['x'] * $point['outX'] + $point['z'] * $point['outZ'];
            self::assertGreaterThan(0.0, $outward);
        }
    }
}

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

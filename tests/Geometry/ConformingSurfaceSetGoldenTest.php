<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\ConformingSurfaceSet;
use PHPolygon\Geometry\RegionShape;
use PHPUnit\Framework\TestCase;

/**
 * Pins the output of a junction set (borders, an inlay, a cut, hems) to numbers
 * taken from the engine before the meshing fast paths went in, so a faster
 * clipper must give the same meshes.
 */
final class ConformingSurfaceSetGoldenTest extends TestCase
{
    /** part => [vertices, indices, sum of positions + uvs + normals] */
    private const array GOLDEN = [
        'kerb' => [559, 1866, -1834.300012326],
        'paving' => [793, 3888, -2198.996713490],
        'plaza_border' => [399, 1692, 6942.658062644],
        'plaza' => [405, 2028, 6941.437064828],
        'gravel' => [342, 1695, 3275.105014669],
        'marker' => [33, 120, 59.387522469],
        'paving.hem' => [580, 1968, -2014.634154289],
        'plaza.hem' => [272, 921, 4786.772260527],
        'gravel.hem' => [186, 624, 1774.526507245],
    ];

    public function testAJunctionSetKeepsItsMeshes(): void
    {
        $hill = static fn (float $x, float $z): float => 3.0 * 1.0 / (1.0 + ($x * $x + $z * $z) / 60.0) + 0.05 * $x;
        $set = new ConformingSurfaceSet($hill, cell: 0.5, lift: 0.025, hemWidth: 0.35, hemSink: 0.05);
        $set->add('paving', RegionShape::polyline([[-14.0, 1.0], [0.0, -1.0], [10.0, -3.0]], 1.5))
            ->add('paving', RegionShape::circle(0.0, -1.0, 2.6))
            ->add('plaza', RegionShape::circle(-6.0, 14.0, 5.0))
            ->add('gravel', RegionShape::polyline([[0.0, -1.0], [-2.0, 6.0], [-6.0, 12.0]], 0.9))
            ->border('plaza', 0.45, 'plaza_border')
            ->border('paving', 0.3, 'kerb')
            ->inlay('marker', RegionShape::circle(0.0, -1.0, 0.8))
            ->cut(RegionShape::rect(6.0, -2.0, 2.0, 2.0));
        $parts = $set->build();

        self::assertSame(array_keys(self::GOLDEN), array_keys($parts));
        foreach (self::GOLDEN as $name => [$vertices, $indices, $sum]) {
            $m = $parts[$name];
            self::assertSame($vertices, intdiv(count($m->vertices), 3), "{$name} vertices");
            self::assertSame($indices, count($m->indices), "{$name} indices");
            self::assertEqualsWithDelta($sum, array_sum($m->vertices) + array_sum($m->uvs) + array_sum($m->normals), 1e-4, "{$name} content");
        }
    }
}

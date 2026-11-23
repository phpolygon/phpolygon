<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\ConformingSurfaceSet;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\RegionShape;
use PHPUnit\Framework\TestCase;

/**
 * abut(..., closeWedge: true): the two side edges of the wedge on which the
 * surface fades inside the neighbour get a hem down to ground - hemSink.
 */
final class ConformingSurfaceWedgeTest extends TestCase
{
    private const float LIFT = 0.025;
    private const float SINK = 0.05;
    private const float LAP = 0.4;

    private static function ground(float $x, float $z): float
    {
        return 0.04 * $x + 0.02 * $z;
    }

    private static function east(): RegionShape
    {
        return RegionShape::rect(10.0, 0.0, 8.0, 20.0); // x 6..14
    }

    private static function west(): RegionShape
    {
        return RegionShape::rect(-18.0, 0.0, 4.0, 20.0); // x -20..-16
    }

    /** closeWedge per neighbour (west: null = no west neighbour) */
    private static function set(bool $closeEast, ?bool $closeWest = null, float $minWidth = 0.0): ConformingSurfaceSet
    {
        $set = new ConformingSurfaceSet(self::ground(...), cell: 0.5, lift: self::LIFT, hemWidth: 0.35, hemSink: self::SINK);
        $set->add('main', RegionShape::polyline([[$closeWest === null ? -12.0 : -17.0, 0.0], [8.0, 0.0]], 1.5))
            ->border('main', 0.3, 'kerb')
            ->abut(self::east(), self::LAP, $minWidth, $closeEast);
        if ($closeWest !== null) {
            $set->abut(self::west(), self::LAP, 0.0, $closeWest);
        }

        return $set;
    }

    /** @return list<array{float, float, float}> */
    private static function points(MeshData $m): array
    {
        $out = [];
        for ($i = 0, $n = count($m->vertices); $i < $n; $i += 3) {
            $out[] = [$m->vertices[$i], $m->vertices[$i + 1], $m->vertices[$i + 2]];
        }

        return $out;
    }

    /**
     * Open edges (used by one triangle) of all parts together that rise above the ground by more than $above.
     *
     * @param array<string, MeshData> $parts
     */
    private static function openEdgesAbove(array $parts, float $above): int
    {
        $merged = MeshData::merge(...array_values($parts));
        $height = [];
        foreach (self::points($merged) as [$x, $y, $z]) {
            $height[sprintf('%.5f:%.5f', round($x, 5) + 0.0, round($z, 5) + 0.0)] = $y - self::ground($x, $z);
        }
        $n = 0;
        foreach (array_keys(ConformingRegionMeshTest::openEdges($merged)) as $edge) {
            foreach (explode('|', $edge) as $end) {
                if (($height[$end] ?? 0.0) > $above) {
                    $n++;
                    break;
                }
            }
        }

        return $n;
    }

    public function testTheWedgeSideEdgesAreOpenWithoutTheFlagAndClosedWith(): void
    {
        self::assertGreaterThan(0, self::openEdgesAbove(self::set(false)->build(), 0.012), 'the wedge edges are open (up to 25 mm) by default');
        self::assertSame(0, self::openEdgesAbove(self::set(true)->build(), 0.012), 'closed with the flag');
    }

    public function testTheDefaultStaysAsItWas(): void
    {
        $omitted = (new ConformingSurfaceSet(self::ground(...), cell: 0.5, lift: self::LIFT, hemWidth: 0.35, hemSink: self::SINK))
            ->add('main', RegionShape::polyline([[-12.0, 0.0], [8.0, 0.0]], 1.5))->border('main', 0.3, 'kerb')
            ->abut(self::east(), self::LAP)
            ->build();
        $off = self::set(false)->build();
        foreach ($omitted as $name => $m) {
            self::assertSame($m->vertices, $off[$name]->vertices, $name);
            self::assertSame($m->indices, $off[$name]->indices, $name);
        }
        $on = self::set(true)->build();
        self::assertNotSame($off['main.hem']->vertices, $on['main.hem']->vertices, 'the flag adds hem');
        // Surface and kerb are the same either way: only the hem changes.
        self::assertSame($off['main']->vertices, $on['main']->vertices);
        self::assertSame($off['kerb']->vertices, $on['kerb']->vertices);
    }

    public function testTheHemInsideTheNeighbourHugsTheFadedEdgeAndSinksUnderTheGround(): void
    {
        $on = self::set(true)->build();
        $insideHem = 0;
        foreach (self::points($on['main.hem']) as [$x, $y, $z]) {
            $dn = self::east()->distance($x, $z);
            $lift = $y - self::ground($x, $z);
            self::assertLessThanOrEqual(self::LIFT + 1e-9, $lift, 'the hem never stands above the full lift');
            self::assertGreaterThanOrEqual(-self::SINK - 1e-9, $lift);
            self::assertGreaterThanOrEqual(-self::LAP - 1e-6, $dn, 'nothing beyond the cut line');
            if ($dn < -0.02) {
                $insideHem++;
                $u = min(1.0, -$dn / self::LAP);
                self::assertLessThanOrEqual(self::LIFT * (1.0 - $u * $u * (3.0 - 2.0 * $u)) + 2e-3, $lift, 'inside, the hem starts at most at the faded lift');
            }
        }
        self::assertGreaterThan(6, $insideHem, 'there is hem along the wedge edges');
        // Still no kerb inside the neighbour.
        foreach (self::points($on['kerb']) as [$x, , $z]) {
            self::assertGreaterThanOrEqual(-1e-6, self::east()->distance($x, $z));
        }
    }

    public function testOnlyTheNeighbourThatAsksGetsTheClosedWedge(): void
    {
        $parts = self::set(true, false)->build(); // east closes, west does not
        $inEast = 0;
        foreach (self::points($parts['main.hem']) as [$x, , $z]) {
            $inEast += self::east()->distance($x, $z) < -0.02 ? 1 : 0;
            self::assertGreaterThanOrEqual(-1e-6, self::west()->distance($x, $z), 'no hem inside the west neighbour');
        }
        self::assertGreaterThan(0, $inEast);

        $both = self::set(true, true)->build();
        $inWest = 0;
        foreach (self::points($both['main.hem']) as [$x, , $z]) {
            $inWest += self::west()->distance($x, $z) < -0.02 ? 1 : 0;
        }
        self::assertGreaterThan(0, $inWest, 'both close: hem inside both');
        self::assertSame(0, self::openEdgesAbove($both, 0.012));
    }

    public function testItWorksTogetherWithMinWidth(): void
    {
        // The fillet of the sliver scenario beside the east neighbour, wedge edges and sliver cut both closed.
        $set = new ConformingSurfaceSet(self::ground(...), cell: 0.5, lift: self::LIFT, hemWidth: 0.35, hemSink: self::SINK);
        $set->add('main', RegionShape::polyline([[-8.0, 0.0], [8.0, 0.0]], 1.5))
            ->add('main', RegionShape::polygon([[6.5, 2.5], [5.5, 2.5], [2.6, 12.0], [7.0, 12.0]]))
            ->border('main', 0.3, 'kerb')
            ->abut(RegionShape::rect(10.0, 0.0, 8.0, 40.0), self::LAP, 1.2, true);
        self::assertSame(0, self::openEdgesAbove($set->build(), 0.012));
    }
}

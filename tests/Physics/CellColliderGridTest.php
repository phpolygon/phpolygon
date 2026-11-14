<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Physics;

use PHPUnit\Framework\TestCase;
use PHPolygon\Physics\CellColliderGrid;

class CellColliderGridTest extends TestCase
{
    /**
     * Boxes with float32-exact coordinates (multiples of 1/8), so the brute-force
     * reference and the grid read the same numbers.
     *
     * @return list<array{0: int, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}>
     */
    private static function randomBoxes(int $seed, int $n, float $ox, float $oz, float $span, int $idBase): array
    {
        mt_srand($seed);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $x = $ox + mt_rand(0, (int) ($span * 8)) / 8;
            $z = $oz + mt_rand(0, (int) ($span * 8)) / 8;
            $y = mt_rand(0, 80) / 8;
            $out[] = [$idBase + $i, $x, $y, $z, $x + mt_rand(1, 160) / 8, $y + mt_rand(1, 80) / 8, $z + mt_rand(1, 160) / 8];
        }

        return $out;
    }

    /**
     * @param list<array{0: int, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}> $boxes
     * @return list<int>
     */
    private static function brute(array $boxes, float $x0, float $y0, float $z0, float $x1, float $y1, float $z1): array
    {
        $ids = [];
        foreach ($boxes as $b) {
            if ($b[1] < $x1 && $b[4] > $x0 && $b[2] < $y1 && $b[5] > $y0 && $b[3] < $z1 && $b[6] > $z0) {
                $ids[] = $b[0];
            }
        }
        sort($ids);

        return $ids;
    }

    /** @param list<int> $ids */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    public function testQueryMatchesBruteForceAcrossCellsAndBoxSizes(): void
    {
        $grid = new CellColliderGrid(4.0);
        $all = [];
        foreach ([['a', 0.0, 0.0, 1], ['b', 64.0, 0.0, 1000], ['c', 0.0, 64.0, 2000]] as [$cell, $ox, $oz, $base]) {
            $boxes = self::randomBoxes($base, 300, $ox, $oz, 60.0, $base);
            $all = array_merge($all, $boxes);
            $grid->add($cell, CellColliderGrid::packBoxes($boxes));
        }
        // A ground slab spanning the whole cell lands on the "large" list.
        $slab = [9999, 0.0, -1.0, 0.0, 60.0, 0.0, 60.0];
        $all[] = $slab;
        $grid->add('a', CellColliderGrid::packBoxes(array_merge(self::randomBoxes(1, 300, 0.0, 0.0, 60.0, 1), [$slab])));
        $grid->setActive(['a', 'b', 'c']);

        mt_srand(77);
        for ($q = 0; $q < 200; $q++) {
            $x = mt_rand(-80, 1500) / 8;
            $z = mt_rand(-80, 1500) / 8;
            $y = mt_rand(-16, 100) / 8;
            $w = mt_rand(1, 240) / 8;
            $expected = self::brute($all, $x, $y, $z, $x + $w, $y + $w, $z + $w);
            $this->assertSame($expected, self::sorted($grid->query($x, $y, $z, $x + $w, $y + $w, $z + $w)), "query {$q}");
        }
    }

    public function testEachIdIsReturnedOnceEvenWhenABoxSitsInManyBuckets(): void
    {
        $grid = new CellColliderGrid(2.0);
        $grid->add('a', CellColliderGrid::packBoxes([[1, 0.0, 0.0, 0.0, 6.0, 1.0, 6.0], [2, 20.0, 0.0, 20.0, 21.0, 1.0, 21.0]]));
        $grid->setActive(['a']);

        $this->assertSame([1], $grid->query(-1.0, -1.0, -1.0, 10.0, 2.0, 10.0));
    }

    public function testOnlyActiveCellsAreQueried(): void
    {
        $grid = new CellColliderGrid();
        $grid->add('near', CellColliderGrid::packBoxes([[1, 0.0, 0.0, 0.0, 1.0, 1.0, 1.0]]));
        $grid->add('far', CellColliderGrid::packBoxes([[2, 0.0, 0.0, 0.0, 1.0, 1.0, 1.0]]));

        $this->assertSame([], $grid->query(0.0, 0.0, 0.0, 1.0, 1.0, 1.0), 'nothing is active yet');

        $change = $grid->setActive(['near']);
        $this->assertSame(['near'], $change['activated']);
        $this->assertSame([1], $grid->query(0.0, 0.0, 0.0, 1.0, 1.0, 1.0));
        $this->assertSame(1, $grid->activeColliderCount());
        $this->assertSame(2, $grid->colliderCount());

        $change = $grid->setActive(['far']);
        $this->assertSame(['far'], $change['activated']);
        $this->assertSame(['near'], $change['deactivated']);
        $this->assertSame([2], $grid->query(0.0, 0.0, 0.0, 1.0, 1.0, 1.0));
    }

    public function testCellsStreamInAndOutWithTheirBoxes(): void
    {
        $grid = new CellColliderGrid();
        $grid->setActive(['a']);
        $this->assertFalse($grid->isActive('a'), 'active but not loaded yet');

        $grid->add('a', CellColliderGrid::packBoxes([[7, 0.0, 0.0, 0.0, 1.0, 1.0, 1.0]]));
        $this->assertTrue($grid->isActive('a'), 'a cell that was already marked active takes part on arrival');
        $this->assertSame([7], $grid->query(0.5, 0.5, 0.5, 0.6, 0.6, 0.6));

        $this->assertTrue($grid->remove('a'));
        $this->assertFalse($grid->remove('a'));
        $this->assertSame([], $grid->query(0.5, 0.5, 0.5, 0.6, 0.6, 0.6));
        $this->assertSame(0, $grid->cellCount());
        $this->assertSame(0, $grid->activeCellCount());
    }

    public function testReplacingACellReplacesItsBoxes(): void
    {
        $grid = new CellColliderGrid();
        $grid->add('a', CellColliderGrid::packBoxes([[1, 0.0, 0.0, 0.0, 1.0, 1.0, 1.0]]));
        $grid->setActive(['a']);
        $grid->query(0.0, 0.0, 0.0, 1.0, 1.0, 1.0);

        $grid->add('a', CellColliderGrid::packBoxes([[2, 5.0, 0.0, 5.0, 6.0, 1.0, 6.0]]));

        $this->assertSame([], $grid->query(0.0, 0.0, 0.0, 1.0, 1.0, 1.0));
        $this->assertSame([2], $grid->query(5.0, 0.0, 5.0, 6.0, 1.0, 6.0));
    }

    public function testTouchingFacesDoNotOverlap(): void
    {
        $grid = new CellColliderGrid();
        $grid->add('a', CellColliderGrid::packBoxes([[1, 0.0, 0.0, 0.0, 1.0, 1.0, 1.0]]));
        $grid->setActive(['a']);

        $this->assertSame([], $grid->query(1.0, 0.0, 0.0, 2.0, 1.0, 1.0));
        $this->assertSame([], $grid->query(0.0, 1.0, 0.0, 1.0, 2.0, 1.0));
        $this->assertSame([1], $grid->query(0.99, 0.0, 0.0, 2.0, 1.0, 1.0));
    }

    public function testEmptyCellAndEmptyQuery(): void
    {
        $grid = new CellColliderGrid();
        $this->assertSame(0, $grid->add('empty', ''));
        $grid->setActive(['empty']);

        $this->assertSame([], $grid->query(-1e6, -1e6, -1e6, 1e6, 1e6, 1e6));
    }

    public function testPrepareBuildsTheIndexAheadOfTheFirstQuery(): void
    {
        $grid = new CellColliderGrid();
        $grid->add('a', CellColliderGrid::packBoxes(self::randomBoxes(3, 50, 0.0, 0.0, 30.0, 0)));
        $grid->prepare('a');
        $grid->prepare('missing');
        $grid->setActive(['a']);

        $this->assertNotSame([], $grid->query(0.0, -1.0, 0.0, 40.0, 20.0, 40.0));
    }

    public function testMalformedInputIsRejected(): void
    {
        $grid = new CellColliderGrid();
        try {
            $grid->add('a', str_repeat("\0", 29));
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        CellColliderGrid::packBoxes([[1, 1.0, 0.0, 0.0, 0.0, 1.0, 1.0]]);
    }

    public function testPackedFormatIsTwentyEightBytesPerBox(): void
    {
        $packed = CellColliderGrid::packBoxes(self::randomBoxes(1, 10, 0.0, 0.0, 10.0, 0));

        $this->assertSame(10 * CellColliderGrid::RECORD_BYTES, strlen($packed));
    }
}

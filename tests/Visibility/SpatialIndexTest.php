<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Visibility;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Frustum;
use PHPolygon\Visibility\EntryKind;
use PHPolygon\Visibility\SpatialIndex;

final class SpatialIndexTest extends TestCase
{
    /** Eye at origin looking down -Z, 60 degree square frustum, far 500. */
    private static function planes(): array
    {
        return Frustum::planes(
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
            Mat4::lookAt(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0), new Vec3(0.0, 1.0, 0.0)),
        );
    }

    public function testRegisterPlacesHandleInItsCell(): void
    {
        $index = new SpatialIndex();
        $index->register(1, 10.0, 0.0, 10.0, 1.0);

        $key = SpatialIndex::cellKeyFor(10.0, 10.0);
        self::assertSame([1], $index->handlesInCell($key));
        self::assertTrue($index->has(1));
    }

    public function testUpdateMovesHandleBetweenCells(): void
    {
        $index = new SpatialIndex();
        $index->register(1, 10.0, 0.0, 10.0, 1.0);
        $oldKey = SpatialIndex::cellKeyFor(10.0, 10.0);

        $index->update(1, 500.0, 0.0, 500.0);
        $newKey = SpatialIndex::cellKeyFor(500.0, 500.0);

        self::assertSame([], $index->handlesInCell($oldKey));
        self::assertSame([1], $index->handlesInCell($newKey));
    }

    public function testUpdateOnUnregisteredHandleIsANoop(): void
    {
        $index = new SpatialIndex();
        $index->update(99, 1.0, 2.0, 3.0);
        self::assertFalse($index->has(99));
    }

    public function testRemoveDropsEmptyCellAndBounds(): void
    {
        $index = new SpatialIndex();
        $index->register(1, 10.0, 0.0, 10.0, 1.0);
        $index->remove(1);

        self::assertFalse($index->has(1));
        self::assertSame([], $index->cellKeys());
    }

    public function testRemoveRebuildsBoundsFromRemainingMembers(): void
    {
        $index = new SpatialIndex();
        // Both in the same cell; entry 2 has the far larger radius and sits
        // deep behind the camera (y = 400) - while present, it pulls the
        // cell's Y range (and thus whether the cell can be "outside") along
        // with it. Removing it must shrink the cell's bounds back to just
        // entry 1's small sphere, which classify() can observe: with entry 2
        // gone, the cell (centred at x=z=1, well inside the frustum) stays
        // classified, and a point far outside entry 1's actual extent no
        // longer falsely keeps the cell's bounds huge.
        $index->register(1, 1.0, 0.0, 1.0, 2.0);
        $index->register(2, 1.0, 400.0, 1.0, 300.0);
        $index->remove(2);

        self::assertSame([1], $index->handlesInCell('0,0'));
        [$inside, $intersected] = $index->classify(self::planes());
        self::assertTrue(isset($inside['0,0']) || isset($intersected['0,0']), 'entry 1 alone keeps this cell in frustum');
    }

    public function testClassifyMarksCellsOutsideInsideAndIntersected(): void
    {
        $index = new SpatialIndex();
        // Deep in front of the camera, far enough out (z = -300) that the
        // frustum is wide relative to one 32 m cell: the WHOLE cell at
        // x in [0, 32) is inside every plane, not just this point.
        $index->register(1, 16.0, 0.0, -300.0, 0.5);
        // At z = -100 the 60 degree frustum is ~57.7 m wide; a sphere
        // centred at x = 57 straddles the right plane.
        $index->register(2, 57.0, 0.0, -100.0, 2.0);
        // Far outside, behind the camera.
        $index->register(3, 0.0, 0.0, 50.0, 0.5);

        [$inside, $intersected] = $index->classify(self::planes());

        $cellOf = static fn(float $x, float $z): string => SpatialIndex::cellKeyFor($x, $z);

        self::assertArrayHasKey($cellOf(16.0, -300.0), $inside, 'a sphere deep inside the frustum classifies its cell as inside');
        self::assertArrayHasKey($cellOf(57.0, -100.0), $intersected, 'a sphere straddling a plane classifies its cell as intersected');
        self::assertArrayNotHasKey($cellOf(0.0, 50.0), $inside);
        self::assertArrayNotHasKey($cellOf(0.0, 50.0), $intersected, 'a cell entirely behind the camera is outside - absent from both sets');
    }

    public function testUnboundedEntryNeverEntersACellOrInflatesBounds(): void
    {
        $index = new SpatialIndex();
        $index->register(1, 0.0, 0.0, 0.0, 0.0, unbounded: true);

        self::assertSame([], $index->cellKeys());
        self::assertSame([1], $index->unboundedHandles());
        self::assertTrue($index->has(1));
    }

    public function testAlwaysVisibleEntryNeverEntersACellEither(): void
    {
        $index = new SpatialIndex();
        $index->register(1, 0.0, 0.0, 0.0, 1.0, alwaysVisible: true);

        self::assertSame([], $index->cellKeys());
        self::assertSame([1], $index->alwaysVisibleHandles());
        self::assertTrue($index->has(1));
    }

    public function testReRegisterReplacesThePreviousEntryCleanly(): void
    {
        $index = new SpatialIndex();
        $index->register(1, 10.0, 0.0, 10.0, 1.0, castsShadow: false);
        $index->register(1, 10.0, 0.0, 10.0, 1.0, castsShadow: true, kind: EntryKind::Entity);

        $entry = $index->entry(1);
        self::assertNotNull($entry);
        self::assertTrue($entry['castsShadow']);
        // Still only counted once in its cell, not twice.
        self::assertCount(1, $index->handlesInCell(SpatialIndex::cellKeyFor(10.0, 10.0)));
    }

    public function testLargeEntryInflatesItsCellsXZBoundsBySitsOwnRadius(): void
    {
        $index = new SpatialIndex();
        // Centre sits in cell (0,0) but the 40 m radius reaches well past
        // the cell's own 32 m edge - the far right frustum plane at this
        // depth cuts through the sphere well outside the bare cell square.
        $index->register(1, 0.0, 0.0, -10.0, 40.0);

        [$inside, $intersected] = $index->classify(self::planes());

        $key = SpatialIndex::cellKeyFor(0.0, -10.0);
        self::assertTrue(isset($inside[$key]) || isset($intersected[$key]), 'the inflated cell bounds must not be reported outside');
    }
}

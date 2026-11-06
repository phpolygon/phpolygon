<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Lighting\LightGrid;

final class LightGridTest extends TestCase
{
    public function testAPointReadsTheLightsThatReachItsCell(): void
    {
        // A row of street lamps 20 m apart, radius 12 m, and one far away.
        $lights = [];
        for ($i = 0; $i < 10; $i++) {
            $lights[] = [20.0 * $i, 5.0, 0.0, 12.0, 1.0, 0.8, 0.6];
        }
        $lights[] = [500.0, 5.0, 500.0, 12.0, 1.0, 1.0, 1.0];
        $grid = new LightGrid($lights, 8.0);

        $near = $grid->lightsAt(41.0, 1.0);
        self::assertContains(2, $near, 'the lamp standing there');
        self::assertNotContains(10, $near, 'not the far one');
        self::assertNotContains(7, $near, 'not a lamp 100 m away');
        self::assertContains(10, $grid->lightsAt(501.0, 499.0));
        self::assertSame([], $grid->lightsAt(-500.0, 0.0), 'outside the grid');
    }

    public function testAFullCellKeepsTheStrongestLights(): void
    {
        $lights = [];
        for ($i = 0; $i < LightGrid::MAX_PER_CELL + 8; $i++) {
            $lights[] = [0.0, 1.0, 0.0, 10.0, $i === 0 ? 50.0 : 0.1, 0.0, 0.0];
        }
        $grid = new LightGrid($lights, 16.0);
        $at = $grid->lightsAt(0.0, 0.0);

        self::assertCount(LightGrid::MAX_PER_CELL, $at);
        self::assertSame(0, $at[0], 'the strong light first');
    }

    public function testTheGridStaysWithinTheMaximumSide(): void
    {
        $grid = new LightGrid([[0.0, 0.0, 0.0, 1.0, 1.0, 1.0, 1.0], [10000.0, 0.0, 10000.0, 1.0, 1.0, 1.0, 1.0]], 4.0);

        self::assertLessThanOrEqual(LightGrid::MAX_SIDE + 1, $grid->nx);
        self::assertLessThanOrEqual(LightGrid::MAX_SIDE + 1, $grid->nz);
        self::assertContains(1, $grid->lightsAt(10000.0, 10000.0));
    }

    public function testBuffersHaveTheShaderLayout(): void
    {
        $grid = new LightGrid([[1.0, 2.0, 3.0, 4.0, 0.5, 0.25, 0.125]], 16.0);

        self::assertSame([1.0, 2.0, 3.0, 4.0, 0.5, 0.25, 0.125, 0.0], array_values(unpack('g*', $grid->lightBytes())));
        self::assertSame((LightGrid::HEADER + 2 * $grid->nx * $grid->nz) * 4, strlen($grid->cellBytes()));
        $head = array_values(unpack('V8', $grid->cellBytes()));
        self::assertSame([$grid->nx, $grid->nz], [$head[0], $head[1]]);
        self::assertSame(1, $head[5], 'light count');
        self::assertSame([0.0 + $grid->originX, $grid->originZ, 1.0 / $grid->cell, 1.0], $grid->gridUniform());
    }
}

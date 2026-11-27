<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\ProcModeShaderRegistry;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The program names of the grid-less twins and the way back to the grid program (the fallback
 * when a twin does not compile).
 */
final class GridlessVariantNamesTest extends TestCase
{
    private static function withGrid(string $variant): string
    {
        $method = new \ReflectionMethod(VioRenderer3D::class, 'withGrid');

        return (string) $method->invoke(null, $variant);
    }

    public function testASuffixTwinOfAGamesVariantTurnsBackIntoTheVariant(): void
    {
        self::assertSame('water', self::withGrid('water+nogrid'));
        self::assertSame('water+cutout', self::withGrid('water+nogrid+cutout'));
    }

    public function testTheBaseTwinTurnsBackIntoTheBaseProgram(): void
    {
        self::assertSame(ProcModeShaderRegistry::BASE_VARIANT, self::withGrid(ProcModeShaderRegistry::NOGRID_VARIANT));
        self::assertSame(ProcModeShaderRegistry::BASE_VARIANT . '+cutout', self::withGrid(ProcModeShaderRegistry::NOGRID_VARIANT . '+cutout'));
    }

    public function testTheCutTwinTurnsBackIntoTheSurfaceCutProgram(): void
    {
        self::assertSame(ProcModeShaderRegistry::SURFACE_CUT_VARIANT, self::withGrid(ProcModeShaderRegistry::NOGRID_CUT_VARIANT));
        self::assertSame(ProcModeShaderRegistry::SURFACE_CUT_VARIANT . '+cutout', self::withGrid(ProcModeShaderRegistry::NOGRID_CUT_VARIANT . '+cutout'));
    }

    /** The programs a renderer without the grid by default falls back to, and the ones it warms. */
    public function testTheGridProgramsOfASkipRendererMapBothWays(): void
    {
        $without = new \ReflectionMethod(VioRenderer3D::class, 'withoutGrid');
        $twin = new \ReflectionMethod(VioRenderer3D::class, 'gridTwinOf');
        self::assertSame('water+cutout', $without->invoke(null, 'water+grid+cutout'));
        self::assertSame(ProcModeShaderRegistry::BASE_VARIANT, $without->invoke(null, ProcModeShaderRegistry::GRID_VARIANT));
        self::assertSame(ProcModeShaderRegistry::SURFACE_CUT_VARIANT, $without->invoke(null, ProcModeShaderRegistry::GRID_CUT_VARIANT));
        self::assertSame('water+grid+cutout', $twin->invoke(null, 'water+cutout'));
        self::assertSame(ProcModeShaderRegistry::GRID_VARIANT . '+cutout', $twin->invoke(null, ProcModeShaderRegistry::BASE_VARIANT . '+cutout'));
        self::assertNull($twin->invoke(null, ProcModeShaderRegistry::LITE_VARIANT));
    }

    public function testAProgramWithTheGridIsLeftAlone(): void
    {
        foreach (['water', ProcModeShaderRegistry::LITE_VARIANT, ProcModeShaderRegistry::BASE_VARIANT . '+cutout'] as $variant) {
            self::assertSame($variant, self::withGrid($variant));
        }
    }
}

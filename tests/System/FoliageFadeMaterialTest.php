<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\System\FoliageInstanceSystem;
use PHPUnit\Framework\TestCase;

/** Shader plan 6.1: plants near maxDistance dissolve through a faded material copy. */
final class FoliageFadeMaterialTest extends TestCase
{
    public function testFadeCopyKeepsTheMaterialAndAddsTheDistanceBand(): void
    {
        MaterialRegistry::register('fade_test_leaf', new Material(albedo: new Color(0.2, 0.5, 0.1), roughness: 0.7));

        $id = FoliageInstanceSystem::fadeMaterial('fade_test_leaf', 100.0);
        $fade = MaterialRegistry::get($id);

        self::assertSame('fade_test_leaf#fade100', $id);
        self::assertNotNull($fade);
        self::assertSame([100.0 * FoliageInstanceSystem::FADE_START, 100.0], $fade->fadeDistance);
        self::assertSame(0.7, $fade->roughness);
        self::assertSame($id, FoliageInstanceSystem::fadeMaterial('fade_test_leaf', 100.0));
    }

    public function testUnknownMaterialStaysAsIs(): void
    {
        self::assertSame('fade_test_missing', FoliageInstanceSystem::fadeMaterial('fade_test_missing', 50.0));
    }

    public function testFadeIsOffByDefault(): void
    {
        self::assertSame([0.0, 0.0], (new Material())->fadeDistance);
    }
}

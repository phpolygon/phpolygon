<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\Component\InstancedTerrain;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\InstancedTerrainSystem;
use PHPUnit\Framework\TestCase;

final class InstancedTerrainSystemTest extends TestCase
{
    protected function setUp(): void
    {
        MaterialRegistry::clear();
    }

    public function testEmitsOneDrawPerMaterialGroup(): void
    {
        MaterialRegistry::register('grain_a', new Material(albedo: new Color(0.6, 0.6, 0.6)));
        MaterialRegistry::register('grain_b', new Material(albedo: new Color(0.7, 0.5, 0.3)));

        $world = new World();
        $cmds  = new RenderCommandList();
        $world->addSystem(new InstancedTerrainSystem($cmds));

        $entity = $world->createEntity();
        $terrain = new InstancedTerrain();
        $terrain->meshId = 'sand_grain';
        $terrain->matricesByMaterial = [
            'grain_a' => [Mat4::identity(), Mat4::identity()],
            'grain_b' => [Mat4::identity()],
        ];
        $entity->attach($terrain);

        $world->render();

        $draws = $cmds->ofType(DrawMeshInstanced::class);
        $this->assertCount(2, $draws);
    }

    public function testTransparentMaterialIsSkippedWithWarning(): void
    {
        MaterialRegistry::register('opaque_grain', new Material(albedo: new Color(0.6, 0.6, 0.6)));
        MaterialRegistry::register('glass_grain',  new Material(albedo: new Color(0.6, 0.7, 0.9), alpha: 0.5));

        $world = new World();
        $cmds  = new RenderCommandList();
        $world->addSystem(new InstancedTerrainSystem($cmds));

        $entity = $world->createEntity();
        $terrain = new InstancedTerrain();
        $terrain->meshId = 'sand_grain';
        $terrain->matricesByMaterial = [
            'opaque_grain' => [Mat4::identity()],
            'glass_grain'  => [Mat4::identity(), Mat4::identity()],
        ];
        $entity->attach($terrain);

        $errors = [];
        set_error_handler(static function (int $level, string $msg) use (&$errors): bool {
            $errors[] = [$level, $msg];
            return true;
        }, E_USER_WARNING);

        try {
            $world->render();
        } finally {
            restore_error_handler();
        }

        $draws = $cmds->ofType(DrawMeshInstanced::class);
        $this->assertCount(1, $draws);
        $this->assertSame('opaque_grain', $draws[0]->materialId);

        $this->assertCount(1, $errors);
        $this->assertSame(E_USER_WARNING, $errors[0][0]);
        $this->assertStringContainsString("'glass_grain'", $errors[0][1]);
        $this->assertStringContainsString('not depth-sorted', $errors[0][1]);
    }

    public function testTransparentMaterialWarningOnlyFiresOncePerMaterialId(): void
    {
        MaterialRegistry::register('glass_grain', new Material(albedo: new Color(0, 0, 1), alpha: 0.3));

        $world = new World();
        $cmds  = new RenderCommandList();
        $world->addSystem(new InstancedTerrainSystem($cmds));

        $entity = $world->createEntity();
        $terrain = new InstancedTerrain();
        $terrain->meshId = 'sand_grain';
        $terrain->matricesByMaterial = ['glass_grain' => [Mat4::identity()]];
        $entity->attach($terrain);

        $errors = [];
        set_error_handler(static function (int $level, string $msg) use (&$errors): bool {
            $errors[] = $msg;
            return true;
        }, E_USER_WARNING);

        try {
            // Three render passes - the warning should fire exactly once.
            $world->render();
            $world->render();
            $world->render();
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $errors);
    }
}

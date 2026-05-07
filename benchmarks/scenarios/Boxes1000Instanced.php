<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Scenarios;

use PHPolygon\Benchmarks\Scenario;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Engine;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Camera3DSystem;

/**
 * Same 1000 boxes as Boxes1000 but rendered through a single
 * DrawMeshInstanced command per frame. Comparing this to Boxes1000 shows
 * how much CPU overhead the per-entity DrawMesh path costs at this size.
 */
final class Boxes1000Instanced implements Scenario
{
    public function name(): string
    {
        return 'boxes-1000-instanced';
    }

    public function setUp(Engine $engine): void
    {
        MeshRegistry::register('bench_box', BoxMesh::generate(1.0, 1.0, 1.0));

        $world = $engine->world;

        if ($engine->renderer3D !== null && $engine->commandList3D !== null) {
            $world->addSystem(new Camera3DSystem($engine->commandList3D, $engine->getConfig()->width, $engine->getConfig()->height));
            $world->addSystem(new InstancedBoxesSystem($engine->commandList3D, $this->buildMatrices()));
        }

        $cam = $world->createEntity();
        $world->attachComponent($cam->id, new Transform3D(
            new Vec3(0.0, 12.0, 35.0),
            Quaternion::identity(),
            new Vec3(1.0, 1.0, 1.0),
        ));
        $world->attachComponent($cam->id, new Camera3DComponent(fov: 60.0));
    }

    public function tickFrame(Engine $engine, int $frame, float $dt): void
    {
    }

    /**
     * @return list<Mat4>
     */
    private function buildMatrices(): array
    {
        $matrices = [];
        for ($x = 0; $x < 10; $x++) {
            for ($y = 0; $y < 10; $y++) {
                for ($z = 0; $z < 10; $z++) {
                    $matrices[] = Mat4::translation(
                        ($x - 5) * 1.5,
                        $y * 1.5,
                        ($z - 5) * 1.5,
                    );
                }
            }
        }
        return $matrices;
    }
}

/**
 * Internal system for the Boxes1000Instanced scenario - emits one
 * DrawMeshInstanced per frame regardless of entity count.
 */
final class InstancedBoxesSystem extends AbstractSystem
{
    /**
     * @param list<Mat4> $matrices
     */
    public function __construct(
        private readonly RenderCommandList $commandList,
        private readonly array $matrices,
    ) {}

    public function render(World $world): void
    {
        $this->commandList->add(new DrawMeshInstanced(
            meshId: 'bench_box',
            materialId: 'default',
            matrices: $this->matrices,
            isStatic: true,
        ));
    }
}

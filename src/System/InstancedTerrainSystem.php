<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\InstancedTerrain;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\RenderCommandList;

/**
 * Renders instanced terrain using batched DrawMeshInstanced commands.
 * Each material group becomes one GPU draw call — thousands of grains
 * rendered efficiently in a single pass per material.
 */
class InstancedTerrainSystem extends AbstractSystem
{
    public function __construct(
        private readonly RenderCommandList $commandList,
    ) {}

    public function render(World $world): void
    {
        static $frameCount = 0;

        $entityCount = 0;
        $totalMatrices = 0;
        $materialCount = 0;

        foreach ($world->query(InstancedTerrain::class) as $entity) {
            $terrain = $entity->get(InstancedTerrain::class);
            $entityCount++;

            foreach ($terrain->matricesByMaterial as $materialId => $matrices) {
                $materialCount++;
                $totalMatrices += count($matrices);
                $this->commandList->add(new DrawMeshInstanced(
                    meshId: $terrain->meshId,
                    materialId: $materialId,
                    matrices: $matrices,
                ));
            }
        }

        if ($frameCount < 3) {
            fwrite(STDERR, sprintf(
                "[InstancedTerrain] frame=%d entities=%d materials=%d matrices=%d cmdList=%d\n",
                $frameCount, $entityCount, $materialCount, $totalMatrices, $this->commandList->count(),
            ));
            $frameCount++;
        }
    }
}

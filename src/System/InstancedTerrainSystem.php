<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\InstancedTerrain;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;

/**
 * Renders instanced terrain using batched DrawMeshInstanced commands.
 * Each material group becomes one GPU draw call via glDrawElementsInstanced.
 *
 * ORDERING CONTRACT: this system shares its {@see RenderCommandList} with
 * {@see Renderer3DSystem}, which is the ONLY system that submits the list to
 * the renderer and then clears it (see Renderer3DSystem::render()). Systems
 * render in registration order (World::render()), so InstancedTerrainSystem
 * MUST be added to the world BEFORE Renderer3DSystem — otherwise its
 * DrawMeshInstanced commands are appended to a list that has already been
 * submitted and cleared this frame, and the terrain never appears despite the
 * commands looking correct. Register it right after Camera3DSystem and before
 * Renderer3DSystem.
 *
 * Materials passed here must be opaque (alpha == 1.0). Renderer3DSystem
 * sorts transparent {@see DrawMesh} draws back-to-front, but transparent
 * instanced draws would need per-instance distance sorting (which defeats
 * the point of batching), so the engine deliberately doesn't support it.
 * Transparent materials are skipped with a one-shot E_USER_WARNING per
 * material id rather than rendered out of order.
 */
class InstancedTerrainSystem extends AbstractSystem
{
    /** @var array<string, true> Material ids we've already warned about. */
    private array $warnedTransparent = [];

    public function __construct(
        private readonly RenderCommandList $commandList,
    ) {}

    public function render(World $world): void
    {
        foreach ($world->query(InstancedTerrain::class) as $entity) {
            $terrain = $entity->get(InstancedTerrain::class);

            foreach ($terrain->matricesByMaterial as $materialId => $matrices) {
                if ($this->isTransparent($materialId)) {
                    continue;
                }
                $this->commandList->add(new DrawMeshInstanced(
                    meshId: $terrain->meshId,
                    materialId: $materialId,
                    matrices: $matrices,
                    isStatic: true,
                ));
            }
        }
    }

    private function isTransparent(string $materialId): bool
    {
        $material = MaterialRegistry::get($materialId);
        if ($material === null || $material->alpha >= 1.0) {
            return false;
        }
        if (!isset($this->warnedTransparent[$materialId])) {
            $this->warnedTransparent[$materialId] = true;
            trigger_error(
                "InstancedTerrainSystem: material '{$materialId}' has alpha < 1.0 but instanced "
                . 'draws are not depth-sorted. Skipping its instances. Use Renderer3DSystem with '
                . 'individual MeshRenderer entities for transparent geometry.',
                E_USER_WARNING,
            );
        }
        return true;
    }
}

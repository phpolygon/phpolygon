<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Mat4;

/**
 * Holds pre-computed instanced matrices for terrain grain rendering.
 * Grouped by material ID for batched DrawMeshInstanced calls.
 */
#[Serializable]
class InstancedTerrain extends AbstractComponent
{
    /** @var string Mesh ID to render for each instance */
    public string $meshId = 'sand_grain';

    /** @var array<string, list<Mat4>> Material ID => transform matrices */
    public array $matricesByMaterial = [];
}

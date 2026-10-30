<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

/**
 * What a character stands on, as {@see GroundProbe::under()} reports it.
 *
 * - $meshId: the mesh of its solid {@see \PHPolygon\Component\MeshCollider3D},
 *   else of its {@see \PHPolygon\Component\MeshRenderer}, else null (a heightmap,
 *   an invisible box).
 * - $materialId: of its MeshRenderer, else null.
 */
readonly class GroundHit
{
    public function __construct(
        public int $entityId,
        public ?string $meshId,
        public ?string $materialId,
    ) {}
}

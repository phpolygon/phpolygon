<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Mat4;

readonly class DrawMesh
{
    public function __construct(
        public string $meshId,
        public string $materialId,
        public Mat4 $modelMatrix,
        /** When true, skip this draw in the deferred G-buffer prepass (no SSAO/SDF-AO/SSR). */
        public bool $excludeFromGbuffer = false,
        /**
         * World matrix this entity was drawn with in the previous frame, for
         * per-object motion vectors. Null when it did not move (or was not drawn
         * last frame): its motion then comes from the camera alone.
         */
        public ?Mat4 $prevModelMatrix = null,
    ) {}
}

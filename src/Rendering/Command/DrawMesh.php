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
    ) {}
}

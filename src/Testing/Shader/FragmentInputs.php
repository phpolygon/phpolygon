<?php

declare(strict_types=1);

namespace PHPolygon\Testing\Shader;

use PHPolygon\Math\Vec3;

/**
 * Per-fragment varying inputs consumed by {@see MeshShaderEvalCpu}.
 * Mirrors the subset of `in` / `varying` values from mesh3d.frag.glsl
 * that the simplified PBR composer actually reads.
 */
final readonly class FragmentInputs
{
    public function __construct(
        /** Surface normal in world space (need not be unit length). */
        public Vec3 $normal,
        /** Fragment position in world space. */
        public Vec3 $worldPos,
        /** Camera position in world space. */
        public Vec3 $cameraPos,
    ) {}
}

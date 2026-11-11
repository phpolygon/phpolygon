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
        /**
         * False keeps this draw out of the shadow-map pass (it still renders
         * and receives shadows). Set from MeshRenderer::$castShadows.
         */
        public bool $castShadows = true,
        /**
         * Three floats for `v_instance_params`, null = zeros; for a draw of many
         * instances see InstanceParams. Needs ProcModeShaderRegistry::enableInstanceParams().
         *
         * @var list<float>|null
         */
        public ?array $params = null,
        /**
         * True for a VisibilityService shadow caster outside the camera's
         * view (see VisibilityService::shadowOnlyEntities()): the renderer's
         * camera passes (prepass, opaque, meshlets, transparent) must skip
         * this draw entirely, but the shadow pass - which reads the full,
         * unfiltered command list - still finds and casts it. Minimap,
         * planar reflection and the ray-traced scene collector read the full
         * list too and are unaffected: a shadow-only draw may legitimately
         * be visible from THEIR viewpoint even though it is not from the
         * main camera's.
         */
        public bool $shadowOnly = false,
    ) {}
}

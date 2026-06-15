<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Rendering\Quality\FieldtracingMode;

/**
 * Configure Fieldtracing (SDF global illumination) for the frame.
 *
 * The mode is evaluated against the renderer's capability tier; on backends that
 * cannot satisfy it the renderer silently degrades (it never crashes) — see the
 * FieldtracingMode tier table in PHPOLYGON_FIELDTRACING.md §8. On the headless
 * NullRenderer3D the command is recorded but executes nothing, so the
 * RenderCommandList stays inspectable for tests.
 *
 * Like every render command this is an immutable value object: appended during
 * the scene tick, flushed once per frame by the Renderer3DSystem.
 */
readonly class SetFieldtracing
{
    public function __construct(
        public FieldtracingMode $mode,
        public float $intensity = 1.0,
        public int   $bounces   = 1,   // ignored unless mode == SdfBounce
        public float $aoRadius  = 1.5,
    ) {}
}

<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Vec3;

/**
 * Per-frame global wind state. Drives:
 *   - procedural cloth sway (Material::$cloth = true)
 *   - water wave bias (when the embedded Vio water shader is active)
 *   - any future wind-aware procedural animation
 *
 * Push once per frame from a wind-controlling system (typically the
 * existing `WindSystem` reading the `Wind` component on the
 * environment entity). When no `SetWind` arrives in a given frame the
 * renderer keeps the default `(0, 0, 1) * 0.5` calm-air state so
 * meshes still animate subtly rather than freeze stiff.
 */
readonly class SetWind
{
    /**
     * @param Vec3  $direction World-space wind vector. Magnitude is
     *                         normalised in-shader; scale via $intensity.
     * @param float $intensity 0 = calm, 1 = strong breeze, > 1 = storm.
     *                         Multiplied with each material's
     *                         clothStrength when computing per-vertex
     *                         sway, so a single global setting can
     *                         drive an entire scene's cloth.
     */
    public function __construct(
        public Vec3 $direction = new Vec3(0.0, 0.0, 1.0),
        public float $intensity = 0.5,
    ) {}
}

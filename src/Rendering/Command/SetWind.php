<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Vec3;

/**
 * Per-frame global wind state. Drives:
 *   - procedural cloth sway (Material::$cloth = true)
 *   - foliage sway (Material::$foliageWind = true): trees, palms, bushes
 *   - water wave bias (when the Vio water shader is active)
 *   - any future wind-aware procedural animation
 *
 * Push once per frame from a wind-controlling system - `WindSystem` does it
 * when it is given the frame's RenderCommandList, reading the `Wind`
 * component on the environment entity. When no `SetWind` arrives in a given
 * frame the renderer keeps the last state (initially `(0, 0, 1) * 0.5` calm
 * air) so meshes still animate subtly rather than freeze stiff.
 */
readonly class SetWind
{
    /**
     * @param Vec3  $direction        World-space wind vector. Magnitude is
     *                                normalised in-shader; scale via $intensity.
     * @param float $intensity        0 = calm, 1 = strong breeze, > 1 = storm.
     *                                Multiplied with each material's
     *                                clothStrength / the foliage bend and
     *                                windFlutter, so a single global setting
     *                                drives an entire scene.
     * @param float $foliageMotion    Global scale of the foliage sway only:
     *                                1 = normal, 0.3 = reduced motion, 0 = still
     *                                (an accessibility setting of the game).
     * @param float $foliageFadeStart Camera distance (m) where the foliage sway
     *                                starts to fade out (far plants stand still,
     *                                no shimmer).
     * @param float $foliageFadeEnd   Camera distance (m) beyond which foliage no
     *                                longer sways.
     */
    public function __construct(
        public Vec3 $direction = new Vec3(0.0, 0.0, 1.0),
        public float $intensity = 0.5,
        public float $foliageMotion = 1.0,
        public float $foliageFadeStart = 100.0,
        public float $foliageFadeEnd = 130.0,
    ) {}
}

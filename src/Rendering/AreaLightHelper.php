<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\AddPointLight;

/**
 * Forward-renderer area-light approximation.
 *
 * True area lights are typically delivered with LTCs (Linearly
 * Transformed Cosines) or polygon-based form factors that integrate
 * over the light surface analytically. Both require either a sizeable
 * lookup-texture pair or per-vertex tessellation; neither fits the
 * forward shader's "point + directional only" loop.
 *
 * As a forward-pipeline-friendly stand-in, AreaLightHelper splits a
 * rectangular emitter into a regular grid of point-light samples. Each
 * sample carries a fraction of the total radiance so the surface
 * receives the same total energy regardless of the chosen sample count.
 *
 * Compared to a single point light at the centre this:
 *   - softens the specular highlight to a streaky strip (correct shape
 *     for a rectangular emitter)
 *   - widens the diffuse falloff zone
 *   - smooths shadow penumbra when used together with the engine's
 *     PCF / cloud shadow accumulators
 *
 * The shader still sees ordinary point lights, so the helper works
 * transparently across all three rendering backends.
 *
 * Usage:
 *
 * ```php
 * AreaLightHelper::pushRectangle(
 *     $commandList,
 *     center: new Vec3(0.0, 4.0, -3.0),
 *     orientation: Quaternion::identity(),
 *     width: 3.0,
 *     height: 1.5,
 *     color: new Color(1.0, 0.92, 0.78),
 *     intensity: 4.0,
 *     samples: 4,
 * );
 * ```
 */
final class AreaLightHelper
{
    /**
     * Push a rectangular area light into $commandList as a samples x samples
     * grid of AddPointLight commands. Total emitted intensity is preserved
     * (intensity / sample-count) so doubling the sample count produces a
     * smoother light without changing total scene radiance.
     *
     * @param int $samples per-axis sample count (>= 1). Final point-light
     *                    count is $samples * $samples; mind the engine's
     *                    u_point_lights[32] cap (a single area light's grid
     *                    plus other scene lights must fit the budget).
     */
    public static function pushRectangle(
        RenderCommandList $commandList,
        Vec3 $center,
        Quaternion $orientation,
        float $width,
        float $height,
        Color $color,
        float $intensity,
        float $radius = 12.0,
        int $samples = 2,
    ): void {
        $samples = max(1, $samples);
        $sampleCount = $samples * $samples;
        $perSampleIntensity = $intensity / (float)$sampleCount;

        $rotMatrix = $orientation->toRotationMatrix();
        $halfW = $width  * 0.5;
        $halfH = $height * 0.5;

        for ($iy = 0; $iy < $samples; $iy++) {
            for ($ix = 0; $ix < $samples; $ix++) {
                // Centre of each sub-cell, in light-local space (XY plane).
                $u = ($samples === 1) ? 0.0 : (($ix + 0.5) / $samples - 0.5) * 2.0;
                $v = ($samples === 1) ? 0.0 : (($iy + 0.5) / $samples - 0.5) * 2.0;
                $localOffset = new Vec3($u * $halfW, $v * $halfH, 0.0);
                $worldOffset = $rotMatrix->transformDirection($localOffset);
                $worldPos    = new Vec3(
                    $center->x + $worldOffset->x,
                    $center->y + $worldOffset->y,
                    $center->z + $worldOffset->z,
                );

                $commandList->add(new AddPointLight(
                    position: $worldPos,
                    color: $color,
                    intensity: $perSampleIntensity,
                    radius: $radius,
                ));
            }
        }
    }
}

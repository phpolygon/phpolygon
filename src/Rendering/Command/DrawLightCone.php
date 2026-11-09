<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;

/**
 * The visible beam of a spot light (see {@see \PHPolygon\Component\LightCone}): light
 * the air scatters toward the viewer inside a cone, added over the opaque scene before
 * the transparent pass. Usually emitted by {@see \PHPolygon\System\LightConeSystem};
 * the vio renderer draws it, the GL family and native Vulkan ignore it.
 *
 * `$color * $intensity` is the scattered radiance per metre at the apex: the light's
 * colour and intensity times the cone's density (and its distance fade).
 */
readonly class DrawLightCone
{
    /**
     * @param Vec3  $apex       where the beam leaves the lamp (world space)
     * @param Vec3  $direction  beam axis, unit length
     * @param float $angle      half-angle of the cone in radians (the spot light's)
     * @param float $innerAngle half-angle up to which the beam is at full strength
     * @param float $length     how far along the axis the beam is drawn, metres
     * @param float $range      distance at which the light's falloff reaches 0, metres
     * @param float $axisFade   0..1, how much the beam dims when seen along its axis
     */
    public function __construct(
        public Vec3 $apex,
        public Vec3 $direction,
        public float $angle,
        public float $innerAngle,
        public float $length,
        public float $range,
        public Color $color,
        public float $intensity,
        public float $axisFade = 0.6,
    ) {}
}

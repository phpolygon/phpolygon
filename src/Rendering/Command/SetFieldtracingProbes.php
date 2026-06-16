<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Vec3;

/**
 * Provide the baked irradiance probe field the Fieldtracing ProbesOnly (and
 * higher) tiers sample in the mesh shader for directional ambient GI.
 *
 * The field is COLOURED SH-L1: one RGBA8 3D texture per colour channel
 * ($dataR/$dataG/$dataB, each width*height*depth*4, Z-slices ascending) holding
 * that channel's 4 spherical-harmonic coefficients (R=c0, G=c1, B=c2, A=c3),
 * signed-encoded over [-range, +range]. The mesh shader reconstructs a coloured
 * irradiance E_rgb(n) = c0 + c1*n.x + c2*n.y + c3*n.z per channel for the surface
 * normal n — including the 1-bounce term baked in — replacing the flat analytic
 * hemisphere. World mapping mirrors {@see SetFieldtracingVolume}: world point p
 * maps to UVW = (p - origin) / size.
 *
 * The renderer uploads the three textures and re-uploads only when {@see $version}
 * changes, so it can be emitted every frame cheaply (a static world bumps the
 * version once). Backends without 3D-texture support ignore it and the tier
 * falls back to the analytic hemisphere ambient.
 */
readonly class SetFieldtracingProbes
{
    public function __construct(
        public string $dataR,
        public string $dataG,
        public string $dataB,
        public int    $width,
        public int    $height,
        public int    $depth,
        public Vec3   $origin,
        public Vec3   $size,
        public float  $range,
        public int    $version = 0,
    ) {}
}

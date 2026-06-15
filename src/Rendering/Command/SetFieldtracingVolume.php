<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Vec3;

/**
 * Provide the baked Signed Distance Field volume the Fieldtracing trace pass
 * samples (the SdfOcclusion / SdfBounce tiers). The data is RGBA8 voxels
 * (width*height*depth*4, Z-slices ascending) exactly as produced by
 * {@see \PHPolygon\Fieldtracing\Volume\SdfVolume::toRgba8()}.
 *
 * The renderer uploads the volume to a GPU 3D texture (vio_texture_3d) and
 * re-uploads only when {@see $version} changes, so this command can be emitted
 * every frame cheaply (static worlds bump the version once). Backends without
 * 3D-texture support ignore it and the tier degrades to ProbesOnly.
 *
 * World mapping: a world point p maps to volume UVW = (p - origin) / size; the
 * shader decodes distance as (sample*2 - 1) * range.
 */
readonly class SetFieldtracingVolume
{
    public function __construct(
        public string $data,
        public int    $width,
        public int    $height,
        public int    $depth,
        public Vec3   $origin,
        public Vec3   $size,
        public float  $range,
        public int    $version = 0,
    ) {}
}

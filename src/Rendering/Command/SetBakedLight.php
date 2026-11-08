<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Fieldtracing\Volume\BakedLightVolume;
use PHPolygon\Math\Vec3;

/**
 * Provide the baked light volume (shader plan 3.3): sky and sun visibility on a world
 * grid, baked once from the SDF ({@see \PHPolygon\Fieldtracing\Bake\BakedLightBaker}).
 * With GraphicsSettings::$bakedLight on, the renderer looks it up per pixel from the
 * G-buffer - sky visibility darkens the ambient light, sun visibility the sun - in
 * place of the live SDF trace (which wins when its tier runs).
 *
 * The data is RGBA8 voxels (R = sky, G = sun) exactly as
 * {@see BakedLightVolume::toRgba8()} writes them; the renderer uploads it once per
 * {@see $version}, so the command can be emitted every frame.
 *
 * The sun term fits the baked sun direction only: it fades out as the live sun turns
 * away from {@see $towardSun} (gone at 25 degrees). Near the camera the shadow maps
 * are sharper - between {@see $sunFadeStart} and {@see $sunFadeEnd} (view distance,
 * world units) the baked sun term fades in; 0 / 0 applies it everywhere.
 */
readonly class SetBakedLight
{
    public function __construct(
        public string $data,
        public int $width,
        public int $height,
        public int $depth,
        public Vec3 $origin,
        public Vec3 $size,
        public Vec3 $towardSun,
        public int $version = 0,
        public float $skyStrength = 1.0,
        public float $sunStrength = 1.0,
        public float $sunFadeStart = 0.0,
        public float $sunFadeEnd = 0.0,
    ) {}

    public static function fromVolume(
        BakedLightVolume $volume,
        int $version = 0,
        float $skyStrength = 1.0,
        float $sunStrength = 1.0,
        float $sunFadeStart = 0.0,
        float $sunFadeEnd = 0.0,
    ): self {
        return new self(
            $volume->toRgba8(), $volume->nx, $volume->ny, $volume->nz, $volume->origin, $volume->size(),
            $volume->towardSun, $version, $skyStrength, $sunStrength, $sunFadeStart, $sunFadeEnd,
        );
    }
}

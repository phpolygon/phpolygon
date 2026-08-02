<?php

declare(strict_types=1);

namespace PHPolygon\Terrain;

/**
 * Placement rules for one scattered object set — what may stand where, and how
 * varied it looks.
 *
 * Split out from the component so {@see TerrainScatterGenerator} can be tested
 * and reused without an ECS world, and so the editor's authoring format maps
 * onto one value object rather than a bag of loose arguments.
 */
final readonly class ScatterRules
{
    /**
     * @param float $densityPerUnit Instances per world unit squared at full painted density.
     * @param float $minHeight      Normalised height band start, 0..1 of the terrain's height range.
     * @param float $maxHeight      Normalised height band end.
     * @param float $minSlope       Slope band start, in degrees from horizontal.
     * @param float $maxSlope       Slope band end, in degrees.
     * @param bool  $alignToNormal  Tilt instances onto the surface instead of standing upright.
     * @param float $randomYaw      Random yaw range in degrees.
     */
    public function __construct(
        public int $seed = 1337,
        public float $densityPerUnit = 0.05,
        public float $minHeight = 0.0,
        public float $maxHeight = 1.0,
        public float $minSlope = 0.0,
        public float $maxSlope = 30.0,
        public float $minScale = 0.8,
        public float $maxScale = 1.2,
        public bool $alignToNormal = false,
        public float $randomYaw = 360.0,
    ) {}
}

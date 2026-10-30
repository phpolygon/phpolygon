<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * An upright one-sided quad in the XY plane, centred on the origin, normal +Z.
 *
 * UVs are image coordinates: seen from the front u runs along +X and v from the
 * top edge (0) to the bottom edge (1). An image uploaded top row first, which
 * is how every vio backend takes it, stands upright and is not mirrored.
 * (The faces of {@see BoxMesh} have v = 0 at the bottom, {@see PlaneMesh} lies
 * flat in XZ.) The opaque pass culls nothing, so from behind the image shows
 * mirrored: keep something behind it, such as a second quad turned 180 degrees
 * about Y, which also reads correctly from that side.
 */
class QuadMesh
{
    /** Id of the shared 1 x 1 quad (see {@see unit()}). */
    public const string UNIT_ID = 'quad_unit';

    public static function generate(float $width, float $height): MeshData
    {
        $hw = $width / 2.0;
        $hh = $height / 2.0;

        return new MeshData(
            [-$hw, -$hh, 0.0, $hw, -$hh, 0.0, $hw, $hh, 0.0, -$hw, $hh, 0.0],
            [0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0],
            [0.0, 1.0, 1.0, 1.0, 1.0, 0.0, 0.0, 0.0],
            [0, 1, 2, 0, 2, 3],
        );
    }

    /**
     * The registered 1 x 1 quad. Surfaces of any size share it through the
     * entity's scale, which lets equal surfaces collapse into one instanced draw.
     */
    public static function unit(): string
    {
        if (!MeshRegistry::has(self::UNIT_ID)) {
            MeshRegistry::register(self::UNIT_ID, self::generate(1.0, 1.0));
        }

        return self::UNIT_ID;
    }
}

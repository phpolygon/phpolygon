<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Math\Vec2;
use PHPolygon\Math\Vec3;

/**
 * A wall slab with real openings: the slab spans x in [0, width], y in
 * [0, height], z in [0, thickness]; the front face looks along +Z. Every
 * opening is cut clean through, so its jambs, head and bottom are inner faces
 * of full wall depth (a true reveal), shaded with flat normals.
 *
 * Optional sill and lintel ledges (see WallOpening) sit in front of the front
 * face and are returned as separate parts so a game can give them their own
 * material. UVs are in world metres (times $uvScale): front/back faces use
 * (x, y), reveals use (distance along the opening outline, z).
 *
 * The slab is a closed manifold; openings must lie strictly inside the wall and
 * must not overlap (InvalidArgumentException otherwise). Built on
 * PolygonExtrude and MetricMesh; pure and thread-safe.
 */
final class WallWithOpenings
{
    /**
     * @param list<WallOpening> $openings
     * @return array{wall: MeshData, sills: ?MeshData, lintels: ?MeshData}
     */
    public static function parts(float $width, float $height, float $thickness, array $openings = [], float $uvScale = 1.0): array
    {
        self::validate($width, $height, $openings);

        $holes = [];
        $sills = [];
        $lintels = [];
        foreach ($openings as $o) {
            $shape = Profile::archedRect($o->width, $o->height, $o->archRise, $o->archSegments);
            $hole = [];
            foreach ($shape as $p) {
                $hole[] = new Vec2($o->x + $p->x, $o->y + $p->y);
            }
            $holes[] = $hole;

            if ($o->sillHeight > 0.0) {
                $sills[] = MetricMesh::boxBetween(
                    new Vec3($o->x - $o->sillOverhang, $o->y - $o->sillHeight, $thickness),
                    new Vec3($o->x + $o->width + $o->sillOverhang, $o->y, $thickness + $o->sillProjection),
                    $uvScale,
                );
            }
            if ($o->lintelHeight > 0.0) {
                $top = $o->y + $o->height;
                $lintels[] = MetricMesh::boxBetween(
                    new Vec3($o->x - $o->lintelOverhang, $top, $thickness),
                    new Vec3($o->x + $o->width + $o->lintelOverhang, $top + $o->lintelHeight, $thickness + $o->lintelProjection),
                    $uvScale,
                );
            }
        }

        $outline = [new Vec2(0.0, 0.0), new Vec2($width, 0.0), new Vec2($width, $height), new Vec2(0.0, $height)];
        return [
            'wall' => PolygonExtrude::generate($outline, $thickness, $holes, true, true, $uvScale),
            'sills' => $sills === [] ? null : MeshData::merge(...$sills),
            'lintels' => $lintels === [] ? null : MeshData::merge(...$lintels),
        ];
    }

    /**
     * Wall, sills and lintels merged into one mesh.
     *
     * @param list<WallOpening> $openings
     */
    public static function generate(float $width, float $height, float $thickness, array $openings = [], float $uvScale = 1.0): MeshData
    {
        $p = self::parts($width, $height, $thickness, $openings, $uvScale);
        $all = [$p['wall']];
        if ($p['sills'] !== null) {
            $all[] = $p['sills'];
        }
        if ($p['lintels'] !== null) {
            $all[] = $p['lintels'];
        }
        return MeshData::merge(...$all);
    }

    /** @param list<WallOpening> $openings */
    private static function validate(float $width, float $height, array $openings): void
    {
        $eps = 1e-9;
        foreach ($openings as $i => $o) {
            if ($o->width <= 0.0 || $o->height <= 0.0) {
                throw new \InvalidArgumentException("Opening $i has a non-positive size");
            }
            if ($o->x <= $eps || $o->y <= $eps || $o->x + $o->width >= $width - $eps || $o->y + $o->height >= $height - $eps) {
                throw new \InvalidArgumentException("Opening $i must lie strictly inside the wall (not touch its border)");
            }
            foreach ($openings as $j => $p) {
                if ($j <= $i) {
                    continue;
                }
                if ($o->x < $p->x + $p->width && $p->x < $o->x + $o->width
                    && $o->y < $p->y + $p->height && $p->y < $o->y + $o->height) {
                    throw new \InvalidArgumentException("Openings $i and $j overlap");
                }
            }
        }
    }
}

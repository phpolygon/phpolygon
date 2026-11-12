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
 * The slab is a closed manifold. Openings must not overlap or touch each other
 * and must not touch the top edge. Rectangular/arched openings with y = 0 are
 * floor-level notches (doors, shopfronts; they may also touch the side edges);
 * ring openings (WallOpening::ring/circle) and all others lie strictly inside
 * (InvalidArgumentException otherwise). Built on PolygonExtrude and MetricMesh;
 * pure and thread-safe.
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
        $notches = [];
        $sills = [];
        $lintels = [];
        foreach ($openings as $o) {
            if ($o->ring !== null) {
                $holes[] = $o->ring;
            } elseif ($o->isFloorLevel()) {
                $notches[] = $o;
            } else {
                $hole = [];
                foreach (Profile::archedRect($o->width, $o->height, $o->archRise, $o->archSegments) as $p) {
                    $hole[] = new Vec2($o->x + $p->x, $o->y + $p->y);
                }
                $holes[] = $hole;
            }

            if ($o->sillHeight > 0.0 && !$o->isFloorLevel()) {
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

        return [
            'wall' => PolygonExtrude::generate(self::outline($width, $height, $notches), $thickness, $holes, true, true, $uvScale),
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

    /**
     * The wall outline: the rectangle with every floor-level opening spliced into
     * the bottom edge as a notch (up the left jamb, across the head, down the right
     * jamb). A notch touching a wall edge swallows that outline corner.
     *
     * @param list<WallOpening> $notches
     * @return list<Vec2>
     */
    private static function outline(float $width, float $height, array $notches): array
    {
        if ($notches === []) {
            return [new Vec2(0.0, 0.0), new Vec2($width, 0.0), new Vec2($width, $height), new Vec2(0.0, $height)];
        }
        usort($notches, static fn(WallOpening $a, WallOpening $b): int => $a->x <=> $b->x);
        $eps = 1e-9;
        $count = count($notches);
        $leftCorner = $notches[0]->x <= $eps;
        $rightCorner = $notches[$count - 1]->x + $notches[$count - 1]->width >= $width - $eps;

        $pts = [];
        if (!$leftCorner) {
            $pts[] = new Vec2(0.0, 0.0);
        }
        foreach ($notches as $i => $o) {
            // archedRect is CCW from (0, 0): (0,0) (w,0) (w,spring) arc... (0,spring).
            // The notch path runs the other way round: (0,0) (0,spring) arc... (w,spring) (w,0).
            $ring = Profile::archedRect($o->width, $o->height, $o->archRise, $o->archSegments);
            $path = array_merge([$ring[0]], array_reverse(array_slice($ring, 1)));
            $last = count($path) - 1;
            foreach ($path as $k => $p) {
                if (($k === 0 && $i === 0 && $leftCorner) || ($k === $last && $i === $count - 1 && $rightCorner)) {
                    continue;
                }
                $pts[] = new Vec2($o->x + $p->x, $p->y);
            }
        }
        if (!$rightCorner) {
            $pts[] = new Vec2($width, 0.0);
        }
        $pts[] = new Vec2($width, $height);
        $pts[] = new Vec2(0.0, $height);
        return $pts;
    }

    /** @param list<WallOpening> $openings */
    private static function validate(float $width, float $height, array $openings): void
    {
        $eps = 1e-9;
        foreach ($openings as $i => $o) {
            if ($o->width <= 0.0 || $o->height <= 0.0) {
                throw new \InvalidArgumentException("Opening $i has a non-positive size");
            }
            if ($o->y < -$eps || $o->x < -$eps || $o->x + $o->width > $width + $eps || $o->y + $o->height > $height + $eps) {
                throw new \InvalidArgumentException("Opening $i lies outside the wall");
            }
            if ($o->y + $o->height >= $height - $eps) {
                throw new \InvalidArgumentException("Opening $i must not touch the top edge of the wall");
            }
            if (!$o->isFloorLevel() && ($o->x <= $eps || $o->y <= $eps || $o->x + $o->width >= $width - $eps)) {
                throw new \InvalidArgumentException("Opening $i must lie strictly inside the wall (only floor-level openings, y = 0, may touch the bottom or the side edges)");
            }
            foreach ($openings as $j => $p) {
                if ($j <= $i) {
                    continue;
                }
                // Two notches may not share a jamb: the wall between them would have no width.
                $gap = $o->isFloorLevel() && $p->isFloorLevel() ? $eps : 0.0;
                if ($o->x < $p->x + $p->width + $gap && $p->x < $o->x + $o->width + $gap
                    && $o->y < $p->y + $p->height && $p->y < $o->y + $o->height) {
                    throw new \InvalidArgumentException("Openings $i and $j overlap");
                }
            }
        }
    }
}

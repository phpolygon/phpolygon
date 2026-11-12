<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Vertex animation textures (shader plan 6.3). Bakes the vertex positions of an
 * animation - one MeshData per frame, same vertex order - into an RGBA8 image the
 * vertex stage reads: two texels per vertex and frame (x and y as 16 bits in the first,
 * z in the second), one row per frame, positions relative to the animation's bounds.
 * Cloth, flags, fish tails, crowds of animated props: any deformation, no skeleton.
 */
final class VatBaker
{
    /**
     * With $normals the frames' unit normals (one per vertex) are baked too, as a third
     * texel per vertex: the octahedral-encoded normal as two 16-bit values (u = r,g;
     * v = b,a; each maps 0..65535 to -1..1; decode with the standard octahedral map).
     * The row is then three texels per vertex ('texelsPerVertex'); without $normals the
     * image is byte-identical to what this baker always produced. The engine's vertex
     * stage does not read the normals yet.
     *
     * @param list<MeshData> $frames
     * @return array{rgba: string, width: int, height: int, texelsPerVertex: int, min: array{0: float, 1: float, 2: float}, extent: array{0: float, 1: float, 2: float}}
     */
    public static function bake(array $frames, bool $normals = false): array
    {
        if ($frames === []) {
            throw new \InvalidArgumentException('VatBaker: no frames');
        }
        $count = count($frames[0]->vertices);
        if ($count % 3 !== 0 || $count === 0) {
            throw new \InvalidArgumentException('VatBaker: vertices must be xyz triples');
        }
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        foreach ($frames as $f) {
            if (count($f->vertices) !== $count) {
                throw new \InvalidArgumentException('VatBaker: every frame needs the same vertices');
            }
            if ($normals && count($f->normals) !== $count) {
                throw new \InvalidArgumentException('VatBaker: every frame needs one normal per vertex to bake normals');
            }
            for ($i = 0; $i < $count; $i += 3) {
                for ($a = 0; $a < 3; $a++) {
                    $min[$a] = min($min[$a], $f->vertices[$i + $a]);
                    $max[$a] = max($max[$a], $f->vertices[$i + $a]);
                }
            }
        }
        $extent = [max($max[0] - $min[0], 1e-6), max($max[1] - $min[1], 1e-6), max($max[2] - $min[2], 1e-6)];
        $rgba = '';
        foreach ($frames as $f) {
            for ($i = 0; $i < $count; $i += 3) {
                $q = [];
                for ($a = 0; $a < 3; $a++) {
                    $q[$a] = (int) round(($f->vertices[$i + $a] - $min[$a]) / $extent[$a] * 65535.0);
                }
                $rgba .= chr(($q[0] >> 8) & 0xFF) . chr($q[0] & 0xFF) . chr(($q[1] >> 8) & 0xFF) . chr($q[1] & 0xFF)
                       . chr(($q[2] >> 8) & 0xFF) . chr($q[2] & 0xFF) . "\0\xFF";
                if ($normals) {
                    [$u, $v] = self::octEncode((float) $f->normals[$i], (float) $f->normals[$i + 1], (float) $f->normals[$i + 2]);
                    $rgba .= chr(($u >> 8) & 0xFF) . chr($u & 0xFF) . chr(($v >> 8) & 0xFF) . chr($v & 0xFF);
                }
            }
        }
        $texels = $normals ? 3 : 2;

        return ['rgba' => $rgba, 'width' => intdiv($count, 3) * $texels, 'height' => count($frames), 'texelsPerVertex' => $texels, 'min' => $min, 'extent' => $extent];
    }

    /**
     * Octahedral encoding of a unit vector to two 16-bit values.
     *
     * @return array{0: int, 1: int}
     */
    public static function octEncode(float $x, float $y, float $z): array
    {
        $l = abs($x) + abs($y) + abs($z);
        if ($l < 1e-12) {
            return [32768, 32768];
        }
        $x /= $l;
        $y /= $l;
        if ($z < 0.0) {
            [$x, $y] = [(1.0 - abs($y)) * ($x >= 0.0 ? 1.0 : -1.0), (1.0 - abs($x)) * ($y >= 0.0 ? 1.0 : -1.0)];
        }

        return [(int) round(($x * 0.5 + 0.5) * 65535.0), (int) round(($y * 0.5 + 0.5) * 65535.0)];
    }

    /**
     * Inverse of {@see octEncode()}: a unit vector.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public static function octDecode(int $u, int $v): array
    {
        $x = $u / 65535.0 * 2.0 - 1.0;
        $y = $v / 65535.0 * 2.0 - 1.0;
        $z = 1.0 - abs($x) - abs($y);
        if ($z < 0.0) {
            [$x, $y] = [(1.0 - abs($y)) * ($x >= 0.0 ? 1.0 : -1.0), (1.0 - abs($x)) * ($y >= 0.0 ? 1.0 : -1.0)];
        }
        $l = sqrt($x * $x + $y * $y + $z * $z);

        return [$x / $l, $y / $l, $z / $l];
    }
}

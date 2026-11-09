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
     * @param list<MeshData> $frames
     * @return array{rgba: string, width: int, height: int, min: array{0: float, 1: float, 2: float}, extent: array{0: float, 1: float, 2: float}}
     */
    public static function bake(array $frames): array
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
            }
        }

        return ['rgba' => $rgba, 'width' => intdiv($count, 3) * 2, 'height' => count($frames), 'min' => $min, 'extent' => $extent];
    }
}

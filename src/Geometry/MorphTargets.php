<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * Morph targets (blend shapes) as sparse deltas against a base mesh: a target stores
 * only the vertices it moves (index, position delta, optional normal delta), so a face
 * with dozens of expression shapes stays small. {@see apply()} adds the weighted deltas;
 * run it before {@see SkinnedMeshData::skin()} when a mesh has both.
 *
 * The vertex count and order of the base mesh never change, so UVs, indices and skin
 * weights stay valid. Pure and deterministic.
 */
final readonly class MorphTargets
{
    /**
     * @param int $vertexCount Vertices of the base mesh the targets belong to.
     * @param array<string, array{indices: list<int>, positions: list<float>, normals: list<float>|null}> $targets
     *        per name: moved vertex indices, 3 position delta floats each and, optionally, 3 normal delta floats each.
     */
    public function __construct(public int $vertexCount, public array $targets)
    {
        foreach ($targets as $name => $t) {
            $n = count($t['indices']);
            if (count($t['positions']) !== $n * 3 || ($t['normals'] !== null && count($t['normals']) !== $n * 3)) {
                throw new \InvalidArgumentException("MorphTargets: target '{$name}' has mismatched delta counts");
            }
            foreach ($t['indices'] as $i) {
                if ($i < 0 || $i >= $vertexCount) {
                    throw new \InvalidArgumentException("MorphTargets: target '{$name}' moves vertex {$i} outside the mesh");
                }
            }
        }
    }

    /**
     * Sparse targets from full meshes with the base's vertex order: every vertex that
     * differs by more than $epsilon becomes a delta.
     *
     * @param array<string, MeshData> $shapes
     */
    public static function fromShapes(MeshData $base, array $shapes, float $epsilon = 1e-7): self
    {
        $n = $base->vertexCount();
        $targets = [];
        foreach ($shapes as $name => $shape) {
            if ($shape->vertexCount() !== $n) {
                throw new \InvalidArgumentException("MorphTargets: shape '{$name}' has a different vertex count");
            }
            $withNormals = count($shape->normals) === count($base->normals) && $base->normals !== [];
            $idx = [];
            $pos = [];
            $nor = [];
            for ($v = 0; $v < $n; $v++) {
                $d = [
                    (float) $shape->vertices[$v * 3] - (float) $base->vertices[$v * 3],
                    (float) $shape->vertices[$v * 3 + 1] - (float) $base->vertices[$v * 3 + 1],
                    (float) $shape->vertices[$v * 3 + 2] - (float) $base->vertices[$v * 3 + 2],
                ];
                if (abs($d[0]) <= $epsilon && abs($d[1]) <= $epsilon && abs($d[2]) <= $epsilon) {
                    continue;
                }
                $idx[] = $v;
                array_push($pos, ...$d);
                if ($withNormals) {
                    array_push(
                        $nor,
                        (float) $shape->normals[$v * 3] - (float) $base->normals[$v * 3],
                        (float) $shape->normals[$v * 3 + 1] - (float) $base->normals[$v * 3 + 1],
                        (float) $shape->normals[$v * 3 + 2] - (float) $base->normals[$v * 3 + 2],
                    );
                }
            }
            $targets[$name] = ['indices' => $idx, 'positions' => $pos, 'normals' => $withNormals ? $nor : null];
        }

        return new self($n, $targets);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->targets);
    }

    /**
     * The base mesh with the weighted deltas added. Unknown names and zero weights are
     * ignored; weights may be negative or above 1 (over-driven expressions).
     *
     * @param array<string, float> $weights
     */
    public function apply(MeshData $base, array $weights): MeshData
    {
        if ($base->vertexCount() !== $this->vertexCount) {
            throw new \InvalidArgumentException('MorphTargets: base mesh has a different vertex count');
        }
        $pos = $base->vertices;
        $nor = $base->normals;
        $touched = [];
        foreach ($weights as $name => $w) {
            if ($w == 0.0 || !isset($this->targets[$name])) {
                continue;
            }
            $t = $this->targets[$name];
            foreach ($t['indices'] as $k => $v) {
                $pos[$v * 3] += $w * $t['positions'][$k * 3];
                $pos[$v * 3 + 1] += $w * $t['positions'][$k * 3 + 1];
                $pos[$v * 3 + 2] += $w * $t['positions'][$k * 3 + 2];
                if ($t['normals'] !== null && isset($nor[$v * 3])) {
                    $nor[$v * 3] += $w * $t['normals'][$k * 3];
                    $nor[$v * 3 + 1] += $w * $t['normals'][$k * 3 + 1];
                    $nor[$v * 3 + 2] += $w * $t['normals'][$k * 3 + 2];
                    $touched[$v] = true;
                }
            }
        }
        foreach ($touched as $v => $_) {
            $l = sqrt($nor[$v * 3] ** 2 + $nor[$v * 3 + 1] ** 2 + $nor[$v * 3 + 2] ** 2);
            if ($l > 1e-12) {
                $nor[$v * 3] /= $l;
                $nor[$v * 3 + 1] /= $l;
                $nor[$v * 3 + 2] /= $l;
            }
        }

        return new MeshData($pos, $nor, $base->uvs, $base->indices, $base->tangents);
    }
}

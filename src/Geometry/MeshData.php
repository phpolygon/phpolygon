<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

readonly class MeshData
{
    /**
     * @param float[]  $vertices Flat array: x,y,z per vertex
     * @param float[]  $normals  Flat array: nx,ny,nz per vertex
     * @param float[]  $uvs      Flat array: u,v per vertex
     * @param int[]    $indices  Triangle list, 3 ints per triangle
     * @param ?float[] $tangents Optional flat array: tx,ty,tz,handedness per
     *                           vertex (4 floats). Required for normal-mapped
     *                           materials so the fragment shader can build a
     *                           correct TBN matrix; otherwise null. The
     *                           handedness component is +1 or -1 and encodes
     *                           the bitangent direction (b = (n × t) * w).
     */
    public function __construct(
        public array $vertices,
        public array $normals,
        public array $uvs,
        public array $indices,
        public ?array $tangents = null,
    ) {}

    public function vertexCount(): int
    {
        return (int)(count($this->vertices) / 3);
    }

    public function triangleCount(): int
    {
        return (int)(count($this->indices) / 3);
    }

    /**
     * Return self if tangents are already populated; otherwise return a new
     * MeshData with tangents computed from the existing vertex / normal /
     * uv / index buffers.
     */
    public function withComputedTangents(): self
    {
        if ($this->tangents !== null) {
            return $this;
        }
        return new self(
            $this->vertices,
            $this->normals,
            $this->uvs,
            $this->indices,
            self::computeTangents($this->vertices, $this->normals, $this->uvs, $this->indices),
        );
    }

    /**
     * Mikk-T-style averaged-per-vertex tangent computation.
     *
     * For each triangle, derive a tangent / bitangent from the UV gradient,
     * accumulate them into per-vertex sums, then orthonormalise against the
     * vertex normal (Gram-Schmidt) and pack into a vec4 with the handedness
     * sign so the fragment shader can reconstruct the bitangent as
     * `b = (n × t) * w`.
     *
     * Degenerate UVs (zero determinant) fall back to a stable basis built
     * from the normal so the buffer never contains NaN.
     *
     * @param  float[] $vertices Flat xyz per vertex
     * @param  float[] $normals  Flat nx,ny,nz per vertex
     * @param  float[] $uvs      Flat u,v per vertex
     * @param  int[]   $indices  Triangle list (3 ints per triangle)
     * @return list<float>       Flat tx,ty,tz,handedness per vertex (vec4)
     */
    public static function computeTangents(array $vertices, array $normals, array $uvs, array $indices): array
    {
        $vertexCount = (int)(count($vertices) / 3);
        // Per-vertex tangent + bitangent accumulators.
        $tan1 = array_fill(0, $vertexCount * 3, 0.0);
        $tan2 = array_fill(0, $vertexCount * 3, 0.0);

        $triCount = (int)(count($indices) / 3);
        for ($t = 0; $t < $triCount; $t++) {
            $i0 = $indices[$t * 3];
            $i1 = $indices[$t * 3 + 1];
            $i2 = $indices[$t * 3 + 2];

            $v0x = $vertices[$i0 * 3];     $v0y = $vertices[$i0 * 3 + 1]; $v0z = $vertices[$i0 * 3 + 2];
            $v1x = $vertices[$i1 * 3];     $v1y = $vertices[$i1 * 3 + 1]; $v1z = $vertices[$i1 * 3 + 2];
            $v2x = $vertices[$i2 * 3];     $v2y = $vertices[$i2 * 3 + 1]; $v2z = $vertices[$i2 * 3 + 2];

            $u0 = $uvs[$i0 * 2]; $w0 = $uvs[$i0 * 2 + 1];
            $u1 = $uvs[$i1 * 2]; $w1 = $uvs[$i1 * 2 + 1];
            $u2 = $uvs[$i2 * 2]; $w2 = $uvs[$i2 * 2 + 1];

            $e1x = $v1x - $v0x; $e1y = $v1y - $v0y; $e1z = $v1z - $v0z;
            $e2x = $v2x - $v0x; $e2y = $v2y - $v0y; $e2z = $v2z - $v0z;

            $du1 = $u1 - $u0; $dw1 = $w1 - $w0;
            $du2 = $u2 - $u0; $dw2 = $w2 - $w0;

            $det = $du1 * $dw2 - $du2 * $dw1;
            if (abs($det) < 1e-9) {
                // Degenerate UVs: skip - vertex falls back to normal-derived basis below.
                continue;
            }
            $r = 1.0 / $det;

            $tx = ($dw2 * $e1x - $dw1 * $e2x) * $r;
            $ty = ($dw2 * $e1y - $dw1 * $e2y) * $r;
            $tz = ($dw2 * $e1z - $dw1 * $e2z) * $r;
            $bx = ($du1 * $e2x - $du2 * $e1x) * $r;
            $by = ($du1 * $e2y - $du2 * $e1y) * $r;
            $bz = ($du1 * $e2z - $du2 * $e1z) * $r;

            foreach ([$i0, $i1, $i2] as $i) {
                $tan1[$i * 3]     += $tx;
                $tan1[$i * 3 + 1] += $ty;
                $tan1[$i * 3 + 2] += $tz;
                $tan2[$i * 3]     += $bx;
                $tan2[$i * 3 + 1] += $by;
                $tan2[$i * 3 + 2] += $bz;
            }
        }

        $out = array_fill(0, $vertexCount * 4, 0.0);
        for ($i = 0; $i < $vertexCount; $i++) {
            $nx = $normals[$i * 3];
            $ny = $normals[$i * 3 + 1];
            $nz = $normals[$i * 3 + 2];
            $tx = $tan1[$i * 3];
            $ty = $tan1[$i * 3 + 1];
            $tz = $tan1[$i * 3 + 2];

            // Gram-Schmidt: t' = t - n * dot(n, t)
            $dot = $nx * $tx + $ny * $ty + $nz * $tz;
            $tx -= $nx * $dot;
            $ty -= $ny * $dot;
            $tz -= $nz * $dot;

            $len = sqrt($tx * $tx + $ty * $ty + $tz * $tz);
            if ($len < 1e-9) {
                // Fallback: pick a stable basis perpendicular to the normal.
                if (abs($nx) <= abs($ny) && abs($nx) <= abs($nz)) {
                    $tx = 0.0; $ty = -$nz; $tz = $ny;
                } elseif (abs($ny) <= abs($nz)) {
                    $tx = -$nz; $ty = 0.0; $tz = $nx;
                } else {
                    $tx = -$ny; $ty = $nx; $tz = 0.0;
                }
                $len = sqrt($tx * $tx + $ty * $ty + $tz * $tz);
                if ($len < 1e-9) {
                    $tx = 1.0; $ty = 0.0; $tz = 0.0; $len = 1.0;
                }
            }
            $tx /= $len; $ty /= $len; $tz /= $len;

            // Handedness: sign of dot(cross(n, t), bitangent_acc).
            $cx = $ny * $tz - $nz * $ty;
            $cy = $nz * $tx - $nx * $tz;
            $cz = $nx * $ty - $ny * $tx;
            $bxa = $tan2[$i * 3];
            $bya = $tan2[$i * 3 + 1];
            $bza = $tan2[$i * 3 + 2];
            $hand = ($cx * $bxa + $cy * $bya + $cz * $bza) < 0.0 ? -1.0 : 1.0;

            $out[$i * 4]     = $tx;
            $out[$i * 4 + 1] = $ty;
            $out[$i * 4 + 2] = $tz;
            $out[$i * 4 + 3] = $hand;
        }

        return array_values($out);
    }

    /**
     * Concatenate any number of MeshData into a single mesh. Indices from
     * later meshes are offset by the running vertex count so the resulting
     * triangle list still references the right vertices.
     *
     * Tangents are preserved only when *every* input has them; if any input
     * lacks tangents the merged mesh's tangents field is null. (Mixing
     * tangent-aware and tangent-less verts in a single buffer would silently
     * desync the TBN basis - dropping is the safe default.)
     */
    public static function merge(self ...$meshes): self
    {
        if ($meshes === []) {
            return new self([], [], [], []);
        }
        if (count($meshes) === 1) {
            return $meshes[0];
        }

        $vertices = [];
        $normals  = [];
        $uvs      = [];
        $indices  = [];
        $tangents = [];
        $allHaveTangents = true;
        $vertexOffset = 0;

        foreach ($meshes as $m) {
            foreach ($m->vertices as $v) { $vertices[] = $v; }
            foreach ($m->normals  as $v) { $normals[]  = $v; }
            foreach ($m->uvs      as $v) { $uvs[]      = $v; }
            foreach ($m->indices  as $i) { $indices[]  = $i + $vertexOffset; }

            if ($m->tangents === null) {
                $allHaveTangents = false;
            } elseif ($allHaveTangents) {
                foreach ($m->tangents as $v) { $tangents[] = $v; }
            }

            $vertexOffset += $m->vertexCount();
        }

        return new self(
            $vertices,
            $normals,
            $uvs,
            $indices,
            $allHaveTangents ? $tangents : null,
        );
    }
}

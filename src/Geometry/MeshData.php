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
     * Axis-aligned bounding box of the positions. An empty mesh yields a zero
     * box at the origin.
     *
     * @return array{min: \PHPolygon\Math\Vec3, max: \PHPolygon\Math\Vec3}
     */
    public function bounds(): array
    {
        $v = $this->vertices;
        $n = count($v);
        if ($n < 3) {
            return ['min' => \PHPolygon\Math\Vec3::zero(), 'max' => \PHPolygon\Math\Vec3::zero()];
        }
        $minX = $maxX = (float) $v[0];
        $minY = $maxY = (float) $v[1];
        $minZ = $maxZ = (float) $v[2];
        for ($i = 3; $i + 2 < $n; $i += 3) {
            $x = (float) $v[$i];
            $y = (float) $v[$i + 1];
            $z = (float) $v[$i + 2];
            if ($x < $minX) { $minX = $x; } elseif ($x > $maxX) { $maxX = $x; }
            if ($y < $minY) { $minY = $y; } elseif ($y > $maxY) { $maxY = $y; }
            if ($z < $minZ) { $minZ = $z; } elseif ($z > $maxZ) { $maxZ = $z; }
        }
        return [
            'min' => new \PHPolygon\Math\Vec3($minX, $minY, $minZ),
            'max' => new \PHPolygon\Math\Vec3($maxX, $maxY, $maxZ),
        ];
    }

    /**
     * Whether every triangle faces the same way (by winding): a flat, one-sided
     * pane, quad or particle. Such a mesh cannot overlap itself on screen, so
     * a renderer that draws a closed transparent mesh far faces first can draw
     * it once. False for anything with faces pointing apart (box, sphere,
     * double-sided plane). Degenerate triangles are ignored.
     */
    public function facesOneWay(): bool
    {
        $v = $this->vertices;
        $idx = $this->indices;
        $nx = $ny = $nz = null;
        for ($t = 0, $n = count($idx) - 2; $t < $n; $t += 3) {
            $a = $idx[$t] * 3;
            $b = $idx[$t + 1] * 3;
            $c = $idx[$t + 2] * 3;
            $ux = $v[$b] - $v[$a];
            $uy = $v[$b + 1] - $v[$a + 1];
            $uz = $v[$b + 2] - $v[$a + 2];
            $wx = $v[$c] - $v[$a];
            $wy = $v[$c + 1] - $v[$a + 1];
            $wz = $v[$c + 2] - $v[$a + 2];
            $cx = $uy * $wz - $uz * $wy;
            $cy = $uz * $wx - $ux * $wz;
            $cz = $ux * $wy - $uy * $wx;
            $len = sqrt($cx * $cx + $cy * $cy + $cz * $cz);
            if ($len < 1e-12) {
                continue;
            }
            if ($nx === null) {
                $nx = $cx / $len;
                $ny = $cy / $len;
                $nz = $cz / $len;
            } elseif (($cx * $nx + $cy * $ny + $cz * $nz) / $len < 0.999) {
                return false;
            }
        }
        return true;
    }

    /**
     * The mesh reflected across the axis-aligned plane through the origin
     * (`$axis` 0 = X, 1 = Y, 2 = Z): positions and normals flip on that axis and
     * every triangle's winding is reversed, so the copy still faces outward. A
     * left part from a right one. Tangents are dropped (recompute when needed).
     */
    public function mirrored(int $axis = 0): self
    {
        $axis = max(0, min(2, $axis));
        $vertices = $this->vertices;
        $normals = $this->normals;
        for ($i = $axis, $n = count($vertices); $i < $n; $i += 3) {
            $vertices[$i] = -$vertices[$i];
        }
        for ($i = $axis, $n = count($normals); $i < $n; $i += 3) {
            $normals[$i] = -$normals[$i];
        }
        $indices = $this->indices;
        for ($i = 0, $n = count($indices); $i + 2 < $n; $i += 3) {
            [$indices[$i + 1], $indices[$i + 2]] = [$indices[$i + 2], $indices[$i + 1]];
        }

        return new self($vertices, $normals, $this->uvs, $indices);
    }

    /**
     * The mesh with $model applied: positions transformed, normals by the inverse
     * transpose (renormalised), winding flipped when $model mirrors. For merging
     * placed meshes into one (HLOD, shader plan 9.1). Tangents are dropped.
     */
    public function transformed(\PHPolygon\Math\Mat4 $model): self
    {
        $m = $model->toArray();
        $inv = $model->inverse()->toArray();
        $vertices = [];
        $normals = [];
        for ($i = 0, $n = count($this->vertices); $i + 2 < $n; $i += 3) {
            $x = (float) $this->vertices[$i];
            $y = (float) $this->vertices[$i + 1];
            $z = (float) $this->vertices[$i + 2];
            $vertices[] = $m[0] * $x + $m[4] * $y + $m[8] * $z + $m[12];
            $vertices[] = $m[1] * $x + $m[5] * $y + $m[9] * $z + $m[13];
            $vertices[] = $m[2] * $x + $m[6] * $y + $m[10] * $z + $m[14];
        }
        for ($i = 0, $n = count($this->normals); $i + 2 < $n; $i += 3) {
            $x = (float) $this->normals[$i];
            $y = (float) $this->normals[$i + 1];
            $z = (float) $this->normals[$i + 2];
            // transpose(inverse) * n: component i is column i of the inverse (column-major
            // elements 4i .. 4i + 2) dotted with the normal.
            $nx = $inv[0] * $x + $inv[1] * $y + $inv[2] * $z;
            $ny = $inv[4] * $x + $inv[5] * $y + $inv[6] * $z;
            $nz = $inv[8] * $x + $inv[9] * $y + $inv[10] * $z;
            $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
            $len = $len > 1e-12 ? $len : 1.0;
            array_push($normals, $nx / $len, $ny / $len, $nz / $len);
        }
        $indices = $this->indices;
        $det = $m[0] * ($m[5] * $m[10] - $m[9] * $m[6]) - $m[4] * ($m[1] * $m[10] - $m[9] * $m[2]) + $m[8] * ($m[1] * $m[6] - $m[5] * $m[2]);
        if ($det < 0.0) {
            for ($i = 0, $n = count($indices); $i + 2 < $n; $i += 3) {
                [$indices[$i + 1], $indices[$i + 2]] = [$indices[$i + 2], $indices[$i + 1]];
            }
        }
        return new self($vertices, $normals, $this->uvs, $indices);
    }

    /**
     * Place many parts and merge them into one mesh in a single pass: the batch form
     * of `transformed()` + `merge()` for assemblies of hundreds of small parts
     * (rivets, slats, bolts). Positions go through the matrix, normals through the
     * cofactor matrix of its upper 3x3 (no 4x4 inverse per part), the winding flips
     * for mirroring matrices. Output equals `merge(...transformed())`; tangents are
     * recomputed once over the result when every part carries tangents (and
     * `$computeTangents` is true), else null. Tangent computation is the dominant cost
     * of the call; pass false when the result needs none.
     *
     * @param list<array{0: self, 1: \PHPolygon\Math\Mat4}> $parts
     */
    public static function mergeTransformed(array $parts, bool $computeTangents = true): self
    {
        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];
        $allHaveTangents = true;
        $offset = 0;
        foreach ($parts as [$mesh, $model]) {
            $m = $model->toArray();
            $c00 = $m[5] * $m[10] - $m[9] * $m[6];
            $c01 = $m[9] * $m[2] - $m[1] * $m[10];
            $c02 = $m[1] * $m[6] - $m[5] * $m[2];
            $c10 = $m[8] * $m[6] - $m[4] * $m[10];
            $c11 = $m[0] * $m[10] - $m[8] * $m[2];
            $c12 = $m[4] * $m[2] - $m[0] * $m[6];
            $c20 = $m[4] * $m[9] - $m[8] * $m[5];
            $c21 = $m[8] * $m[1] - $m[0] * $m[9];
            $c22 = $m[0] * $m[5] - $m[4] * $m[1];
            $det = $m[0] * $c00 + $m[4] * $c01 + $m[8] * $c02;
            $sign = $det < 0.0 ? -1.0 : 1.0;

            $src = $mesh->vertices;
            for ($i = 0, $n = count($src); $i + 2 < $n; $i += 3) {
                $x = (float) $src[$i];
                $y = (float) $src[$i + 1];
                $z = (float) $src[$i + 2];
                $vertices[] = $m[0] * $x + $m[4] * $y + $m[8] * $z + $m[12];
                $vertices[] = $m[1] * $x + $m[5] * $y + $m[9] * $z + $m[13];
                $vertices[] = $m[2] * $x + $m[6] * $y + $m[10] * $z + $m[14];
            }
            $src = $mesh->normals;
            for ($i = 0, $n = count($src); $i + 2 < $n; $i += 3) {
                $x = (float) $src[$i];
                $y = (float) $src[$i + 1];
                $z = (float) $src[$i + 2];
                $nx = ($c00 * $x + $c01 * $y + $c02 * $z) * $sign;
                $ny = ($c10 * $x + $c11 * $y + $c12 * $z) * $sign;
                $nz = ($c20 * $x + $c21 * $y + $c22 * $z) * $sign;
                $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
                $len = $len > 1e-12 ? $len : 1.0;
                $normals[] = $nx / $len;
                $normals[] = $ny / $len;
                $normals[] = $nz / $len;
            }
            foreach ($mesh->uvs as $v) {
                $uvs[] = $v;
            }
            $src = $mesh->indices;
            if ($det < 0.0) {
                for ($i = 0, $n = count($src); $i + 2 < $n; $i += 3) {
                    $indices[] = $src[$i] + $offset;
                    $indices[] = $src[$i + 2] + $offset;
                    $indices[] = $src[$i + 1] + $offset;
                }
            } else {
                foreach ($src as $i) {
                    $indices[] = $i + $offset;
                }
            }
            if ($mesh->tangents === null) {
                $allHaveTangents = false;
            }
            $offset += $mesh->vertexCount();
        }

        $merged = new self($vertices, $normals, $uvs, $indices);
        return $computeTangents && $allHaveTangents && $parts !== [] ? $merged->withComputedTangents() : $merged;
    }

    /**
     * Index (0 = X, 1 = Y, 2 = Z) of an axis name 'x'|'y'|'z' (case-insensitive).
     */
    public static function axisIndex(string $axis): int
    {
        return match (strtolower($axis)) {
            'x' => 0,
            'y' => 1,
            'z' => 2,
            default => throw new \InvalidArgumentException("Axis must be 'x', 'y' or 'z', got '$axis'"),
        };
    }

    /**
     * Rotate the mesh by the proper (cyclic-permutation) rotation that carries axis
     * `$from` onto axis `$to` (0 = X, 1 = Y, 2 = Z): world component (i + k) mod 3 is
     * the local component i, k = to - from. The winding is kept (no mirroring), tangents
     * are permuted along with the positions. Y -> X maps (x, y, z) to (y, z, x),
     * Y -> Z to (z, x, y), Z -> X to (z, x, y), Z -> Y to (y, z, x).
     */
    public function axisRotated(int $from, int $to): self
    {
        $k = (($to - $from) % 3 + 3) % 3;
        if ($k === 0) {
            return $this;
        }
        $permute = static function (array $a, int $stride) use ($k): array {
            $out = [];
            for ($i = 0, $n = count($a); $i + 2 < $n; $i += $stride) {
                $out[$i + ($k) % 3] = $a[$i];
                $out[$i + (1 + $k) % 3] = $a[$i + 1];
                $out[$i + (2 + $k) % 3] = $a[$i + 2];
                if ($stride === 4) {
                    $out[$i + 3] = $a[$i + 3];
                }
            }
            ksort($out);
            return array_values($out);
        };
        return new self(
            $permute($this->vertices, 3),
            $permute($this->normals, 3),
            $this->uvs,
            $this->indices,
            $this->tangents === null ? null : $permute($this->tangents, 4),
        );
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

<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

use PHPolygon\Geometry\LatheMesh;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\SweepMesh;
use PHPolygon\Math\Vec2;
use PHPolygon\Math\Vec3;

/**
 * Mesh accumulator used by {@see PlantGenerator}. Collects parts in one
 * buffer with counter-clockwise outward winding (normals agree with the
 * winding), offers the organic primitives the plant family is built from
 * (deformed foliage clumps, tapered swept tubes, lathed trunks, bands) and
 * recomputes smooth normals by welding coincident positions.
 *
 * @internal
 */
final class PlantMeshBuilder
{
    /** @var list<float> */
    private array $v = [];
    /** @var list<float> */
    private array $n = [];
    /** @var list<float> */
    private array $uv = [];
    /** @var list<int> */
    private array $idx = [];

    public function vertexCount(): int
    {
        return intdiv(count($this->v), 3);
    }

    public function isEmpty(): bool
    {
        return $this->idx === [];
    }

    public function addVertex(float $x, float $y, float $z, float $nx = 0.0, float $ny = 1.0, float $nz = 0.0, float $u = 0.0, float $w = 0.0): int
    {
        $id = intdiv(count($this->v), 3);
        $this->v[] = $x;
        $this->v[] = $y;
        $this->v[] = $z;
        $this->n[] = $nx;
        $this->n[] = $ny;
        $this->n[] = $nz;
        $this->uv[] = $u;
        $this->uv[] = $w;
        return $id;
    }

    public function addTriangle(int $a, int $b, int $c): void
    {
        $this->idx[] = $a;
        $this->idx[] = $b;
        $this->idx[] = $c;
    }

    /** Append a mesh; `$flip` reverses its winding (for generators wound the other way). */
    public function append(MeshData $mesh, bool $flip = false): void
    {
        $base = $this->vertexCount();
        foreach ($mesh->vertices as $f) {
            $this->v[] = (float) $f;
        }
        foreach ($mesh->normals as $f) {
            $this->n[] = (float) $f;
        }
        $uvCount = count($mesh->uvs);
        $expected = $mesh->vertexCount() * 2;
        for ($i = 0; $i < $expected; $i++) {
            $this->uv[] = $i < $uvCount ? (float) $mesh->uvs[$i] : 0.0;
        }
        $ix = $mesh->indices;
        for ($t = 0, $c = count($ix); $t + 2 < $c; $t += 3) {
            $a = $base + (int) $ix[$t];
            $b = $base + (int) $ix[$t + 1];
            $d = $base + (int) $ix[$t + 2];
            if ($flip) {
                $this->addTriangle($a, $d, $b);
            } else {
                $this->addTriangle($a, $b, $d);
            }
        }
    }

    public function appendBuilder(self $other): void
    {
        $this->append($other->toMeshData());
    }

    /**
     * @param bool $smooth recompute normals from the winding, welding
     *                     coincident positions (soft shading across seams)
     */
    public function toMeshData(bool $smooth = false): MeshData
    {
        $mesh = new MeshData($this->v, $this->n, $this->uv, $this->idx);
        return $smooth ? self::smoothNormals($mesh) : $mesh;
    }

    /**
     * Area-weighted vertex normals from the triangle winding, shared by every
     * vertex at the same position (a UV seam or a pole does not crease).
     */
    public static function smoothNormals(MeshData $mesh): MeshData
    {
        $v = $mesh->vertices;
        $idx = $mesh->indices;
        $count = $mesh->vertexCount();
        /** @var array<string, int> $weld */
        $weld = [];
        /** @var list<int> $slot */
        $slot = [];
        for ($i = 0; $i < $count; $i++) {
            $key = (int) round((float) $v[$i * 3] * 4096.0) . ':'
                . (int) round((float) $v[$i * 3 + 1] * 4096.0) . ':'
                . (int) round((float) $v[$i * 3 + 2] * 4096.0);
            $slot[] = $weld[$key] ??= count($weld);
        }
        $acc = array_fill(0, count($weld) * 3, 0.0);
        for ($t = 0, $c = count($idx); $t + 2 < $c; $t += 3) {
            $a = (int) $idx[$t];
            $b = (int) $idx[$t + 1];
            $d = (int) $idx[$t + 2];
            $ux = (float) $v[$b * 3] - (float) $v[$a * 3];
            $uy = (float) $v[$b * 3 + 1] - (float) $v[$a * 3 + 1];
            $uz = (float) $v[$b * 3 + 2] - (float) $v[$a * 3 + 2];
            $wx = (float) $v[$d * 3] - (float) $v[$a * 3];
            $wy = (float) $v[$d * 3 + 1] - (float) $v[$a * 3 + 1];
            $wz = (float) $v[$d * 3 + 2] - (float) $v[$a * 3 + 2];
            $nx = $uy * $wz - $uz * $wy;
            $ny = $uz * $wx - $ux * $wz;
            $nz = $ux * $wy - $uy * $wx;
            foreach ([$a, $b, $d] as $vi) {
                $s = $slot[$vi] * 3;
                $acc[$s] += $nx;
                $acc[$s + 1] += $ny;
                $acc[$s + 2] += $nz;
            }
        }
        $normals = [];
        for ($i = 0; $i < $count; $i++) {
            $s = $slot[$i] * 3;
            $x = $acc[$s];
            $y = $acc[$s + 1];
            $z = $acc[$s + 2];
            $len = sqrt($x * $x + $y * $y + $z * $z);
            if ($len < 1e-12) {
                $normals[] = 0.0;
                $normals[] = 1.0;
                $normals[] = 0.0;
                continue;
            }
            $normals[] = $x / $len;
            $normals[] = $y / $len;
            $normals[] = $z / $len;
        }
        return new MeshData($mesh->vertices, $normals, $mesh->uvs, $mesh->indices);
    }

    /**
     * Apply a position deformation to every vertex (normals untouched - meant
     * for gentle bends such as a trunk lean).
     *
     * @param callable(float, float, float): array{0: float, 1: float, 2: float} $fn
     */
    public static function deform(MeshData $mesh, callable $fn): MeshData
    {
        $v = $mesh->vertices;
        for ($i = 0, $c = count($v); $i + 2 < $c; $i += 3) {
            [$x, $y, $z] = $fn((float) $v[$i], (float) $v[$i + 1], (float) $v[$i + 2]);
            $v[$i] = $x;
            $v[$i + 1] = $y;
            $v[$i + 2] = $z;
        }
        return new MeshData($v, $mesh->normals, $mesh->uvs, $mesh->indices);
    }

    /**
     * A foliage clump: a sphere deformed by a smooth, seam-free wobble,
     * flattened underneath, optionally box-like (`$boxiness` < 1 pushes the
     * surface toward the bounding box - hedge clumps). Normals are smoothed.
     */
    public function addClump(
        float $cx,
        float $cy,
        float $cz,
        float $rx,
        float $ry,
        float $rz,
        int $stacks,
        int $slices,
        float $rough,
        float $seed,
        float $flatBelow = -0.45,
        float $boxiness = 1.0,
    ): void {
        $stacks = max(3, $stacks);
        $slices = max(3, $slices);
        $part = new self();
        for ($st = 0; $st <= $stacks; $st++) {
            $phi = M_PI * $st / $stacks;
            $dy = cos($phi);
            $ring = sin($phi);
            for ($sl = 0; $sl <= $slices; $sl++) {
                $theta = 2.0 * M_PI * ($sl % $slices) / $slices;
                $dx = $ring * cos($theta);
                $dz = $ring * sin($theta);
                $qx = $dx;
                $qy = $dy;
                $qz = $dz;
                if ($boxiness < 1.0) {
                    $qx = ($dx < 0.0 ? -1.0 : 1.0) * abs($dx) ** $boxiness;
                    $qy = ($dy < 0.0 ? -1.0 : 1.0) * abs($dy) ** $boxiness;
                    $qz = ($dz < 0.0 ? -1.0 : 1.0) * abs($dz) ** $boxiness;
                }
                if ($qy < $flatBelow) {
                    $qy = $flatBelow + ($qy - $flatBelow) * 0.3;
                }
                $scale = 1.0 + $rough * self::wobble($dx, $dy, $dz, $seed);
                $part->addVertex(
                    $cx + $qx * $rx * $scale,
                    $cy + $qy * $ry * $scale,
                    $cz + $qz * $rz * $scale,
                    $dx,
                    $dy,
                    $dz,
                    $sl / $slices,
                    $st / $stacks,
                );
            }
        }
        $row = $slices + 1;
        for ($st = 0; $st < $stacks; $st++) {
            for ($sl = 0; $sl < $slices; $sl++) {
                $a = $st * $row + $sl;
                $b = ($st + 1) * $row + $sl;
                $c = ($st + 1) * $row + $sl + 1;
                $d = $st * $row + $sl + 1;
                if ($st !== $stacks - 1) {
                    $part->addTriangle($a, $c, $b);
                }
                if ($st !== 0) {
                    $part->addTriangle($a, $d, $c);
                }
            }
        }
        $this->append(self::smoothNormals($part->toMeshData()));
    }

    /**
     * A tapered swept tube along `$path` (one radius per path point). Built on
     * {@see SweepMesh} with a unit section, each ring scaled around its path
     * point. Ends stay open (tips are thin or hidden in foliage).
     *
     * @param list<Vec3>  $path
     * @param list<float> $radii
     */
    public function addTube(array $path, array $radii, int $sides): void
    {
        $k = count($path);
        if ($k < 2) {
            return;
        }
        $sides = max(3, $sides);
        $tube = SweepMesh::tube(1.0, $sides, $path, false);
        $v = $tube->vertices;
        $stride = $sides + 1;
        for ($ring = 0; $ring < $k; $ring++) {
            $p = $path[$ring];
            $r = $radii[$ring] ?? $radii[count($radii) - 1] ?? 0.1;
            for ($j = 0; $j < $stride; $j++) {
                $o = ($ring * $stride + $j) * 3;
                if (!isset($v[$o + 2])) {
                    continue;
                }
                $v[$o] = $p->x + ((float) $v[$o] - $p->x) * $r;
                $v[$o + 1] = $p->y + ((float) $v[$o + 1] - $p->y) * $r;
                $v[$o + 2] = $p->z + ((float) $v[$o + 2] - $p->z) * $r;
            }
        }
        // SweepMesh winds its side triangles clockwise seen from outside.
        $this->append(new MeshData($v, $tube->normals, $tube->uvs, $tube->indices), flip: true);
    }

    /**
     * A solid of revolution around +Y (trunk, stump, cone tier).
     *
     * @param list<array{0: float, 1: float}> $profile (radius, y) bottom to top
     * @param (callable(float, float, float): array{0: float, 1: float, 2: float})|null $deform
     */
    public function addLathe(array $profile, int $segments, ?callable $deform = null, bool $smooth = false): void
    {
        $points = [];
        foreach ($profile as [$r, $y]) {
            $points[] = new Vec2(max(0.0, $r), $y);
        }
        $mesh = LatheMesh::generate($points, max(3, $segments));
        if ($deform !== null) {
            $mesh = self::deform($mesh, $deform);
        }
        // LatheMesh winds clockwise seen from outside; flip to the family's convention.
        $flipped = new self();
        $flipped->append($mesh, flip: true);
        $this->append($flipped->toMeshData($smooth));
    }

    /**
     * Smooth, deterministic, seam-free wobble of a direction (about -1.5..1.5).
     */
    public static function wobble(float $x, float $y, float $z, float $seed): float
    {
        return sin(3.1 * $x + $seed) * sin(2.7 * $y + 1.3 * $seed) * sin(3.7 * $z + 0.7 * $seed)
            + 0.5 * sin(6.3 * $x - 1.1 * $seed) * sin(5.9 * $z + 2.1 * $seed);
    }
}

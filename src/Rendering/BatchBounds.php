<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Command\DrawMeshInstanced;

/**
 * World-space bounding sphere of a whole instanced batch
 * ({@see DrawMeshInstanced::$bounds}).
 *
 * The renderer derives this sphere itself for every batch whose matrices live
 * on the CPU ({@see InstancedBatchCuller}); a caller only passes one
 *
 *   - for a storage-buffer batch, whose matrices never reach PHP,
 *   - as a tighter or cheaper bound it already knows (a cell, a room), or
 *   - as {@see unbounded()} for a batch that must never be culled.
 *
 * The sphere must enclose every vertex of every instance, including whatever a
 * vertex stage adds on top of the model matrix (sway, waves, displacement).
 */
final class BatchBounds
{
    /**
     * Local radius per mesh id around the mesh ORIGIN (not the mesh centre):
     * [the registered mesh it was measured on, radius].
     *
     * @var array<string, array{0: MeshData, 1: float}>
     */
    private static array $meshRadii = [];

    private static ?self $unbounded = null;

    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly float $z,
        public readonly float $radius,
    ) {}

    /** A batch that is drawn from every viewpoint; the renderer derives nothing for it. */
    public static function unbounded(): self
    {
        return self::$unbounded ??= new self(0.0, 0.0, 0.0, INF);
    }

    public function isUnbounded(): bool
    {
        return $this->radius === INF;
    }

    /**
     * The sphere of a batch from its CPU-side matrices (Mat4, flat or packed
     * mode) and the mesh's registered geometry: what the renderer derives when
     * no bound is given. Null for storage-buffer batches, empty batches and
     * meshes that are not registered.
     */
    public static function of(DrawMeshInstanced $cmd): ?self
    {
        $radius = self::meshRadius($cmd->meshId);
        if ($radius === null || $cmd->storageBuffer !== null) {
            return null;
        }
        if ($cmd->packedMatrices !== '') {
            $sphere = self::sphereOfFlat($cmd->flatMatricesResolved(), max(0, $cmd->instanceCount), $radius);
        } elseif ($cmd->flatMatrices !== []) {
            $sphere = self::sphereOfFlat($cmd->flatMatrices, $cmd->effectiveInstanceCount(), $radius);
        } else {
            $sphere = self::sphereOfMatrices($cmd->matrices, $radius);
        }

        return $sphere === null ? null : new self($sphere[0], $sphere[1], $sphere[2], $sphere[3]);
    }

    /**
     * Largest distance of a vertex of the mesh from its local origin, cached
     * per registered mesh; null when the mesh is not registered. Measured
     * from the origin so the sphere `translation, radius * largest axis scale`
     * holds the instance under any rotation, whatever the mesh's own centre.
     */
    public static function meshRadius(string $meshId): ?float
    {
        $mesh = MeshRegistry::get($meshId);
        if ($mesh === null) {
            return null;
        }
        $cached = self::$meshRadii[$meshId] ?? null;
        if ($cached !== null && $cached[0] === $mesh) {
            return $cached[1];
        }
        $verts = $mesh->vertices;
        $count = count($verts);
        $rSq = 0.0;
        for ($i = 0; $i + 2 < $count; $i += 3) {
            $d = $verts[$i] * $verts[$i] + $verts[$i + 1] * $verts[$i + 1] + $verts[$i + 2] * $verts[$i + 2];
            if ($d > $rSq) {
                $rSq = $d;
            }
        }
        $radius = sqrt($rSq);
        self::$meshRadii[$meshId] = [$mesh, $radius];

        return $radius;
    }

    /** Forget the cached mesh radii (they hold the measured meshes alive). */
    public static function clearCache(): void
    {
        self::$meshRadii = [];
    }

    /**
     * Sphere around `$count` column-major matrices in a flat float buffer, each
     * instance taken as the sphere `translation, $meshRadius * largest axis
     * scale`. The result is the sphere around the box that holds those spheres.
     *
     * @param array<float> $flat
     * @return array{0: float, 1: float, 2: float, 3: float}|null x, y, z, radius; null when empty
     */
    public static function sphereOfFlat(array $flat, int $count, float $meshRadius): ?array
    {
        $count = min($count, intdiv(count($flat), 16));
        if ($count <= 0) {
            return null;
        }
        $minX = $minY = $minZ = INF;
        $maxX = $maxY = $maxZ = -INF;
        for ($i = 0, $o = 0; $i < $count; $i++, $o += 16) {
            $r = $meshRadius * self::maxStretch($flat, $o);
            $x = $flat[$o + 12];
            $y = $flat[$o + 13];
            $z = $flat[$o + 14];
            if ($x - $r < $minX) { $minX = $x - $r; }
            if ($x + $r > $maxX) { $maxX = $x + $r; }
            if ($y - $r < $minY) { $minY = $y - $r; }
            if ($y + $r > $maxY) { $maxY = $y + $r; }
            if ($z - $r < $minZ) { $minZ = $z - $r; }
            if ($z + $r > $maxZ) { $maxZ = $z + $r; }
        }

        return self::sphereOfBox($minX, $minY, $minZ, $maxX, $maxY, $maxZ);
    }

    /**
     * {@see sphereOfFlat()} for a Mat4 batch.
     *
     * @param array<int|string, Mat4> $matrices
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public static function sphereOfMatrices(array $matrices, float $meshRadius): ?array
    {
        if ($matrices === []) {
            return null;
        }
        $minX = $minY = $minZ = INF;
        $maxX = $maxY = $maxZ = -INF;
        foreach ($matrices as $matrix) {
            $m = $matrix->toArray();
            $r = $meshRadius * self::maxStretch($m, 0);
            $x = $m[12];
            $y = $m[13];
            $z = $m[14];
            if ($x - $r < $minX) { $minX = $x - $r; }
            if ($x + $r > $maxX) { $maxX = $x + $r; }
            if ($y - $r < $minY) { $minY = $y - $r; }
            if ($y + $r > $maxY) { $maxY = $y + $r; }
            if ($z - $r < $minZ) { $minZ = $z - $r; }
            if ($z + $r > $maxZ) { $maxZ = $z + $r; }
        }

        return self::sphereOfBox($minX, $minY, $minZ, $maxX, $maxY, $maxZ);
    }

    /**
     * Upper bound of how far the 3x3 part of the matrix at $m[$o ..] stretches
     * a vector. Rotation times scale has orthogonal columns: the longest column
     * is exact. Sheared columns (a rotated child of a non-uniformly scaled
     * parent) can stretch further than any single column, so they take the
     * Frobenius norm, which never falls short.
     *
     * @param array<float> $m
     */
    private static function maxStretch(array $m, int $o): float
    {
        $ax = $m[$o];
        $ay = $m[$o + 1];
        $az = $m[$o + 2];
        $bx = $m[$o + 4];
        $by = $m[$o + 5];
        $bz = $m[$o + 6];
        $cx = $m[$o + 8];
        $cy = $m[$o + 9];
        $cz = $m[$o + 10];
        $a = $ax * $ax + $ay * $ay + $az * $az;
        $b = $bx * $bx + $by * $by + $bz * $bz;
        $c = $cx * $cx + $cy * $cy + $cz * $cz;
        $ab = $ax * $bx + $ay * $by + $az * $bz;
        $ac = $ax * $cx + $ay * $cy + $az * $cz;
        $bc = $bx * $cx + $by * $cy + $bz * $cz;
        if ($ab * $ab > 1.0e-6 * $a * $b || $ac * $ac > 1.0e-6 * $a * $c || $bc * $bc > 1.0e-6 * $b * $c) {
            return sqrt($a + $b + $c);
        }

        return sqrt($a > $b ? ($a > $c ? $a : $c) : ($b > $c ? $b : $c));
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null null when the box is not finite
     */
    private static function sphereOfBox(float $minX, float $minY, float $minZ, float $maxX, float $maxY, float $maxZ): ?array
    {
        $dx = $maxX - $minX;
        $dy = $maxY - $minY;
        $dz = $maxZ - $minZ;
        $radius = 0.5 * sqrt($dx * $dx + $dy * $dy + $dz * $dz);
        // A NaN or infinite matrix gives no usable box: such a batch is never culled.
        if (!is_finite($radius)) {
            return null;
        }

        return [0.5 * ($minX + $maxX), 0.5 * ($minY + $maxY), 0.5 * ($minZ + $maxZ), $radius];
    }
}

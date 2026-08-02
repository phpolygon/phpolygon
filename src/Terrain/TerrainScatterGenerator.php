<?php

declare(strict_types=1);

namespace PHPolygon\Terrain;

use PHPolygon\Math\Vec3;

/**
 * Derives scattered object placements from a heightmap, a painted density map,
 * a seed and a set of rules.
 *
 * Placements are never stored — they are regenerated from those inputs. That
 * keeps a forest stable under editing: because a candidate's position and
 * random values depend only on its grid cell and the seed, sculpting a hill
 * re-drapes the trees already standing on it rather than reshuffling every tree
 * on the map. It also keeps the scene document small, since a painted byte per
 * cell replaces a transform per instance.
 *
 * The editor previews the same scatter with a TypeScript port of this class
 * (`resources/js/terrain/scatter.ts`). The two must agree instance for
 * instance, so {@see hash()} deliberately reproduces JavaScript's 32-bit
 * integer semantics rather than using a nicer PHP hash — see the note there.
 */
final class TerrainScatterGenerator
{
    /**
     * Hard cap on generated instances.
     *
     * A maxed-out density brush over a large terrain can ask for millions of
     * placements; generation stops at the cap rather than thinning, so the
     * shortfall is visible instead of silently changing the look.
     */
    public const DEFAULT_LIMIT = 20000;

    /**
     * Generate placements in terrain-local space.
     *
     * @param  string  $densityMap  One byte of painted density per grid sample,
     *                              row-major (z-major). An empty string means
     *                              nothing is painted and yields no instances.
     * @return list<ScatterInstance>
     */
    public function generate(
        HeightmapData $heightmap,
        string $densityMap,
        ScatterRules $rules,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        if ($rules->densityPerUnit <= 0.0 || $densityMap === '') {
            return [];
        }

        $samples = $heightmap->gridWidth * $heightmap->gridDepth;
        $density = base64_decode($densityMap, true);
        if ($density === false || strlen($density) !== $samples) {
            return [];
        }

        // Expected instances per cell = density (per unit²) × the cell's area.
        $stepX = $heightmap->sizeX / ($heightmap->gridWidth - 1);
        $stepZ = $heightmap->sizeZ / ($heightmap->gridDepth - 1);
        $perCell = $rules->densityPerUnit * $stepX * $stepZ;

        $range = $heightmap->maxHeight - $heightmap->minHeight;
        $instances = [];

        for ($z = 0; $z < $heightmap->gridDepth; $z++) {
            for ($x = 0; $x < $heightmap->gridWidth; $x++) {
                if (count($instances) >= $limit) {
                    return $instances;
                }

                $cell = $z * $heightmap->gridWidth + $x;
                $painted = ord($density[$cell]) / 255.0;
                if ($painted <= 0.0) {
                    continue;
                }

                if (self::hash($cell, 0, $rules->seed) >= $perCell * $painted) {
                    continue;
                }

                $normalisedHeight = $range == 0.0
                    ? 0.0
                    : ($heightmap->heightAt($x, $z) - $heightmap->minHeight) / $range;
                if ($normalisedHeight < $rules->minHeight || $normalisedHeight > $rules->maxHeight) {
                    continue;
                }

                $slope = self::slopeDegrees($heightmap, $x, $z);
                if ($slope < $rules->minSlope || $slope > $rules->maxSlope) {
                    continue;
                }

                // Jitter inside the cell so instances do not sit on a visible grid.
                $wx = $heightmap->worldX($x) + (self::hash($cell, 1, $rules->seed) - 0.5) * $stepX;
                $wz = $heightmap->worldZ($z) + (self::hash($cell, 2, $rules->seed) - 0.5) * $stepZ;

                $scale = $rules->minScale
                    + self::hash($cell, 3, $rules->seed) * ($rules->maxScale - $rules->minScale);
                $yaw = self::hash($cell, 4, $rules->seed) * $rules->randomYaw * M_PI / 180.0;

                $rotation = new Vec3(0.0, $yaw, 0.0);
                if ($rules->alignToNormal) {
                    [$nx, $ny, $nz] = $heightmap->normalAt($x, $z);
                    $rotation = new Vec3(atan2($nz, $ny), $yaw, -atan2($nx, $ny));
                }

                $instances[] = new ScatterInstance(
                    new Vec3($wx, $heightmap->heightAt($x, $z), $wz),
                    $rotation,
                    $scale,
                );
            }
        }

        return $instances;
    }

    /** Slope at a grid cell, in degrees from horizontal. */
    private static function slopeDegrees(HeightmapData $heightmap, int $x, int $z): float
    {
        [, $ny] = $heightmap->normalAt($x, $z);

        return acos(max(-1.0, min(1.0, $ny))) * 180.0 / M_PI;
    }

    /**
     * Deterministic hash of (cell, channel, seed) → [0, 1).
     *
     * This is a literal port of the editor's JavaScript hash, and the masking
     * is what makes it one. In JS the initial sum is float64 arithmetic (exact
     * here — the largest term stays well under 2^53), and the bitwise operators
     * then coerce to 32 bits. Reproducing that in PHP means masking to 32 bits
     * at exactly the points where JS's ToUint32/Math.imul do, or the two
     * implementations diverge and the editor previews a forest the game will
     * not render.
     *
     * A hash rather than a sequential PRNG because placement needs a *specific
     * cell's* random values, looked up in any order and independently of which
     * other cells produced instances.
     */
    public static function hash(int $cell, int $channel, int $seed): float
    {
        $h = $cell * 374761393 + $channel * 668265263 + $seed * 1274126177;

        // ToUint32: keep the low 32 bits. Masking a negative PHP int yields the
        // same bit pattern JS would, so negative seeds behave identically.
        $u = $h & 0xFFFFFFFF;
        $u = ($u ^ ($u >> 13)) & 0xFFFFFFFF;
        // Math.imul: 32-bit multiply, low 32 bits of the product.
        $u = ($u * 1274126177) & 0xFFFFFFFF;
        $u = ($u ^ ($u >> 16)) & 0xFFFFFFFF;

        return $u / 4294967296.0;
    }
}

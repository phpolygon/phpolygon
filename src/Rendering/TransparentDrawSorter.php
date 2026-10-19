<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Command\ClearDepthBuffer;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetShader;

/**
 * Back-to-front order for every transparent draw of a frame.
 *
 * The transparent pass blends in submission order and writes no depth, so the
 * farthest surface has to come first. This sorter reorders all transparent
 * {@see DrawMesh} and {@see DrawMeshInstanced} commands of a command list
 * (whatever system produced them: mesh renderers, particles, instanced
 * batches) by one global key and leaves every other command where it is.
 * Draws never cross a {@see ClearDepthBuffer} or {@see SetShader}: those split
 * the list into segments that are sorted independently.
 *
 * Key: the view-space depth of the point of the draw's world AABB nearest to
 * the camera, taken from the list's last {@see SetCamera} (so it is the camera
 * the frame is rendered with, including eye offsets and interpolation). A long
 * facade in front of a door leaf sorts by its near face, not by a centre that
 * may lie farther away than the leaf behind it. Ties keep submission order
 * (the sort is stable).
 *
 * Layers: reflective water surfaces ({@see ProcModeRegistry::isReflectiveWater()})
 * come before every other transparent draw. A sea or pool plane reaches up to
 * the camera, so its nearest point is always close; by depth alone it would be
 * drawn last and cover the glass, bubbles and panes standing on it.
 *
 * Instances: a transparent instanced batch with at most
 * {@see PER_INSTANCE_SORT_MAX} CPU-side instances also gets its instances
 * sorted back to front (one new command, same batch). Larger batches and
 * GPU storage-buffer batches keep their instance order; a storage-buffer batch
 * has no CPU positions and sorts as nearest (drawn last). A re-ordered batch
 * is emitted as non-static: its order follows the camera, so caching the
 * uploaded instance buffer would freeze the first frame's order.
 */
final class TransparentDrawSorter
{
    /** Instanced batches up to this many instances are sorted per instance. */
    public const PER_INSTANCE_SORT_MAX = 256;

    /** @var array<string, array{0: MeshData, 1: array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}}> meshId => [mesh, [cx, cy, cz, ex, ey, ez]] */
    private static array $boundsCache = [];

    /** @var array<string, array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}> bounds resolved during the current sort() call */
    private static array $callBounds = [];

    /**
     * Sort the transparent draws of $list in place. No-op without a camera.
     */
    public static function sortList(RenderCommandList $list): void
    {
        $camera = $list->lastOfType(SetCamera::class);
        if ($camera === null) {
            return;
        }
        $sorted = self::sort($list->getCommands(), $camera->viewMatrix);
        if ($sorted !== null) {
            $list->replaceCommands($sorted);
        }
    }

    /**
     * @param list<object> $commands
     * @return list<object>|null the reordered list, or null when nothing changed
     */
    public static function sort(array $commands, Mat4 $view): ?array
    {
        $v = $view->toArray();
        self::$callBounds = [];
        // Row 2 of the view matrix: view-space z of a world point.
        $row = [$v[2], $v[6], $v[10], $v[14]];

        /** @var list<array{0: int, 1: int, 2: float, 3: int, 4: object}> $entries [segment, layer, nearDepth, slot, command] */
        $entries = [];
        $changed = false;
        $segment = 0;
        /** @var array<string, int> $layers */
        $layers = [];
        foreach ($commands as $slot => $cmd) {
            if (!($cmd instanceof DrawMesh) && !($cmd instanceof DrawMeshInstanced)) {
                if ($cmd instanceof ClearDepthBuffer || $cmd instanceof SetShader) {
                    // Barrier: draws never move across a depth clear
                    // (compositing layers) or a shader override.
                    $segment++;
                }
                continue;
            }
            // Layer per material id, resolved once per call (-1 = opaque).
            $layer = $layers[$cmd->materialId] ??= self::layerOf($cmd->materialId);
            if ($layer < 0) {
                continue;
            }
            if ($cmd instanceof DrawMesh) {
                $depth = self::nearDepth($cmd->meshId, $cmd->modelMatrix->toArray(), 0, $row);
            } else {
                [$depth, $resorted] = self::instancedNearDepth($cmd, $row);
                if ($resorted !== null) {
                    $cmd = $resorted;
                    $changed = true;
                }
            }
            $entries[] = [$segment, $layer, $depth, $slot, $cmd];
        }

        if ($entries === []) {
            return null;
        }

        $slots = array_column($entries, 3);
        usort($entries, static fn (array $a, array $b): int =>
            ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]) ?: ($b[2] <=> $a[2]) ?: ($a[3] <=> $b[3]));

        foreach ($entries as $i => $entry) {
            if ($commands[$slots[$i]] !== $entry[4]) {
                $commands[$slots[$i]] = $entry[4];
                $changed = true;
            }
        }

        return $changed ? array_values($commands) : null;
    }

    /** Sort layer of a material: -1 opaque/unknown, 0 reflective water, 1 other transparent. */
    private static function layerOf(string $materialId): int
    {
        $material = MaterialRegistry::get($materialId);
        if ($material === null || $material->alpha >= 1.0) {
            return -1;
        }
        return ProcModeRegistry::isReflectiveWater($materialId) ? 0 : 1;
    }

    /** Forget cached mesh bounds (call after re-registering a mesh id with new data). */
    public static function clearCache(): void
    {
        self::$boundsCache = [];
    }

    /**
     * View depth (distance in front of the camera, larger = farther) of the
     * nearest point of the mesh's world AABB, for the model matrix stored at
     * $m[$o .. $o + 15] (column-major).
     *
     * @param array<float> $m
     * @param array{0: float, 1: float, 2: float, 3: float} $row
     */
    private static function nearDepth(string $meshId, array $m, int $o, array $row): float
    {
        [$cx, $cy, $cz, $ex, $ey, $ez] = self::localBounds($meshId);
        // r = viewRow2 * model: view-space z as a function of the local point.
        $r0 = $row[0] * $m[$o]      + $row[1] * $m[$o + 1]  + $row[2] * $m[$o + 2];
        $r1 = $row[0] * $m[$o + 4]  + $row[1] * $m[$o + 5]  + $row[2] * $m[$o + 6];
        $r2 = $row[0] * $m[$o + 8]  + $row[1] * $m[$o + 9]  + $row[2] * $m[$o + 10];
        $r3 = $row[0] * $m[$o + 12] + $row[1] * $m[$o + 13] + $row[2] * $m[$o + 14] + $row[3];
        $zCentre = $r0 * $cx + $r1 * $cy + $r2 * $cz + $r3;
        $zExtent = abs($r0) * $ex + abs($r1) * $ey + abs($r2) * $ez;
        // The camera looks down -Z: the nearest corner has the largest z.
        return -($zCentre + $zExtent);
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $row
     * @return array{0: float, 1: DrawMeshInstanced|null} batch depth, resorted command (or null)
     */
    private static function instancedNearDepth(DrawMeshInstanced $cmd, array $row): array
    {
        if ($cmd->storageBuffer !== null) {
            return [-INF, null];
        }
        if ($cmd->packedMatrices !== '' || $cmd->flatMatrices !== []) {
            $flat = $cmd->flatMatricesResolved();
            $count = intdiv(count($flat), 16);
            if ($cmd->instanceCount >= 0) {
                $count = min($count, $cmd->instanceCount);
            }
            if ($count <= 0) {
                return [-INF, null];
            }
            $depths = [];
            $nearest = INF;
            for ($i = 0; $i < $count; $i++) {
                $d = self::nearDepth($cmd->meshId, $flat, $i * 16, $row);
                $depths[$i] = $d;
                if ($d < $nearest) {
                    $nearest = $d;
                }
            }
            if ($count < 2 || $count > self::PER_INSTANCE_SORT_MAX) {
                return [$nearest, null];
            }
            $order = self::backToFront($depths);
            if ($order === null) {
                return [$nearest, null];
            }
            $sorted = [];
            foreach ($order as $i) {
                for ($k = 0; $k < 16; $k++) {
                    $sorted[] = $flat[$i * 16 + $k];
                }
            }
            if ($cmd->packedMatrices !== '') {
                return [$nearest, DrawMeshInstanced::packed($cmd->meshId, $cmd->materialId, pack('f*', ...$sorted), $count)];
            }
            return [$nearest, DrawMeshInstanced::flat($cmd->meshId, $cmd->materialId, $sorted, $count)];
        }

        $matrices = array_values($cmd->matrices);
        $count = count($matrices);
        if ($count === 0) {
            return [-INF, null];
        }
        $depths = [];
        $nearest = INF;
        foreach ($matrices as $i => $matrix) {
            $d = self::nearDepth($cmd->meshId, $matrix->toArray(), 0, $row);
            $depths[$i] = $d;
            if ($d < $nearest) {
                $nearest = $d;
            }
        }
        if ($count < 2 || $count > self::PER_INSTANCE_SORT_MAX) {
            return [$nearest, null];
        }
        $order = self::backToFront($depths);
        if ($order === null) {
            return [$nearest, null];
        }
        $sorted = [];
        foreach ($order as $i) {
            $sorted[] = $matrices[$i];
        }
        return [$nearest, new DrawMeshInstanced(
            meshId: $cmd->meshId,
            materialId: $cmd->materialId,
            matrices: $sorted,
            instanceCount: $cmd->instanceCount,
        )];
    }

    /**
     * Instance indices farthest first (stable), or null when already in order.
     *
     * @param array<int, float> $depths
     * @return list<int>|null
     */
    private static function backToFront(array $depths): ?array
    {
        $order = array_keys($depths);
        usort($order, static fn (int $a, int $b): int => ($depths[$b] <=> $depths[$a]) ?: ($a <=> $b));
        foreach ($order as $pos => $i) {
            if ($pos !== $i) {
                return $order;
            }
        }
        return null;
    }

    /**
     * Local AABB centre and half extents of a mesh; a unit point when the mesh
     * is unknown. Re-read when the registry hands out a different MeshData.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    private static function localBounds(string $meshId): array
    {
        return self::$callBounds[$meshId] ??= self::resolveBounds($meshId);
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    private static function resolveBounds(string $meshId): array
    {
        $mesh = MeshRegistry::get($meshId);
        if ($mesh === null) {
            return [0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
        }
        $cached = self::$boundsCache[$meshId] ?? null;
        if ($cached !== null && $cached[0] === $mesh) {
            return $cached[1];
        }
        $verts = $mesh->vertices;
        $n = count($verts);
        if ($n < 3) {
            $bounds = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
        } else {
            $minX = $maxX = (float) $verts[0];
            $minY = $maxY = (float) $verts[1];
            $minZ = $maxZ = (float) $verts[2];
            for ($i = 3; $i < $n; $i += 3) {
                $x = $verts[$i];
                $y = $verts[$i + 1];
                $z = $verts[$i + 2];
                if ($x < $minX) { $minX = $x; } elseif ($x > $maxX) { $maxX = $x; }
                if ($y < $minY) { $minY = $y; } elseif ($y > $maxY) { $maxY = $y; }
                if ($z < $minZ) { $minZ = $z; } elseif ($z > $maxZ) { $maxZ = $z; }
            }
            $bounds = [
                ($minX + $maxX) * 0.5, ($minY + $maxY) * 0.5, ($minZ + $maxZ) * 0.5,
                ($maxX - $minX) * 0.5, ($maxY - $minY) * 0.5, ($maxZ - $minZ) * 0.5,
            ];
        }
        self::$boundsCache[$meshId] = [$mesh, $bounds];
        return $bounds;
    }
}

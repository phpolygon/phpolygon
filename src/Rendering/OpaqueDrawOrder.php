<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;

/**
 * Submission order of the opaque pass: every opaque draw (material alpha 1)
 * sorted by (materialId, meshId, moved), so identical draws cluster and the
 * renderer's sticky material / mesh uniforms are uploaded once per run.
 *
 * The order is exactly that of a stable `usort` comparing the three fields
 * with `<=>` - but sorted on one precomputed string key per draw in C
 * (`asort` with SORT_STRING) instead of calling a PHP closure for each of the
 * ~n·log n comparisons, which cost ~0.6 ms per frame at ~400 draws. The key
 * joins the fields with "\0", so a byte-wise compare of the keys is the
 * field-by-field compare. `<=>` compares two NUMERIC strings by value, not by
 * bytes; a frame with a numeric material or mesh id therefore keeps the
 * comparator sort, so the order never differs.
 */
final class OpaqueDrawOrder
{
    private function __construct() {}

    /**
     * @param list<object> $commands the frame's command list
     * @param bool $motion motion vectors this frame: moved DrawMesh entities
     *                     (with a previous model matrix) go last in their run
     * @return list<array{0: string, 1: string, 2: DrawMesh|DrawMeshInstanced, 3: Material, 4: int}>
     */
    public static function sort(array $commands, bool $motion): array
    {
        $opaque = [];
        $keys = [];
        $numeric = false;
        /** @var array<string, Material|false> $materials opaque material per id, false = skip */
        $materials = [];
        /** @var array<string, true> $checked ids already tested for being numeric */
        $checked = [];
        foreach ($commands as $cmd) {
            if (!$cmd instanceof DrawMesh && !$cmd instanceof DrawMeshInstanced) {
                continue;
            }
            $materialId = $cmd->materialId;
            $material = $materials[$materialId] ?? null;
            if ($material === null) {
                $found = MaterialRegistry::get($materialId);
                $material = $materials[$materialId] = $found !== null && $found->alpha >= 1.0 ? $found : false;
            }
            if ($material === false) {
                continue;
            }
            $meshId = $cmd->meshId;
            // Moved entities last within their material + mesh run: the
            // sticky u_has_prev then flips once per run, not per draw.
            $moved = $motion && $cmd instanceof DrawMesh && $cmd->prevModelMatrix !== null ? 1 : 0;
            $keys[] = $materialId . "\0" . $meshId . "\0" . $moved;
            $opaque[] = [$materialId, $meshId, $cmd, $material, $moved];
            if (!isset($checked[$materialId])) {
                $checked[$materialId] = true;
                $numeric = $numeric || is_numeric($materialId);
            }
            if (!isset($checked[$meshId])) {
                $checked[$meshId] = true;
                $numeric = $numeric || is_numeric($meshId);
            }
        }

        if ($numeric) {
            usort($opaque, static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]) ?: ($a[4] <=> $b[4]));
            return $opaque;
        }

        asort($keys, SORT_STRING);
        $sorted = [];
        foreach ($keys as $i => $_) {
            $sorted[] = $opaque[$i];
        }
        return $sorted;
    }
}

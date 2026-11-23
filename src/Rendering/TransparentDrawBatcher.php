<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;

/**
 * Merges runs of identical transparent draws into one instanced draw, AFTER
 * {@see TransparentDrawSorter} has put the list in back-to-front order.
 *
 * A glazed facade is dozens of the same pane: one draw each is dozens of state
 * and uniform changes for nothing. Batching the panes before the sort would
 * place the whole batch at its nearest instance and let other glass that lies
 * between its panes draw in the wrong order. Batching the already sorted list
 * cannot: only draws that are NEXT to each other in the sorted order join, so
 * nothing else is drawn between them, and the instances keep that order (back
 * to front), which is the order the separate draws would have had.
 *
 * What joins (all of it, else the draw stays as it is):
 * - a {@see DrawMesh} with a transparent material, no previous matrix (a mover
 *   keeps its own draw for its motion vector), no per-draw params, not
 *   excluded from the G-buffer, not shadow-only;
 * - a flat one-sided mesh ({@see \PHPolygon\Geometry\MeshData::facesOneWay()}):
 *   panes, quads. A closed mesh is drawn far faces first, then near faces, per
 *   draw – as one instanced draw all far faces of all instances would come before
 *   any near face, and glass boxes that overlap on screen would layer wrongly;
 * - the same mesh id, material id and shadow flag as its neighbour.
 *
 * Neighbour means: the next transparent draw in the list. Opaque draws in
 * between do not matter (the opaque pass is drawn before the transparent one),
 * any other command (camera, light, shader override, depth clear) ends a run,
 * and so does a transparent draw that is not eligible.
 *
 * The result is a non-static {@see DrawMeshInstanced} (its order follows the
 * camera). Opt-in: {@see \PHPolygon\System\Renderer3DSystem::$batchTransparentRuns};
 * without it the sorted list is drawn as before.
 */
final class TransparentDrawBatcher
{
    /** @var array<string, array{int, bool}> meshId => [registry version, flat one-sided] */
    private static array $flatCache = [];

    /**
     * Merge the runs of $list in place.
     *
     * @return int draws saved (separate draws minus batches)
     */
    public static function batchList(RenderCommandList $list): int
    {
        $result = self::batch($list->getCommands());
        if ($result === null) {
            return 0;
        }
        [$commands, $saved] = $result;
        $list->replaceCommands($commands);

        return $saved;
    }

    /**
     * @param list<object> $commands a list already sorted by {@see TransparentDrawSorter}
     * @return array{list<object>, int}|null the new list and the draws saved, or null when nothing merged
     */
    public static function batch(array $commands): ?array
    {
        /** @var array<string, int> $transparent materialId => 1/0, resolved once per call */
        $transparent = [];
        $out = [];
        $saved = 0;
        // The run being built: index in $out of its first draw, and its members.
        $runSlot = -1;
        /** @var list<DrawMesh> $run */
        $run = [];
        $runKey = '';

        $flush = static function () use (&$out, &$run, &$runSlot, &$runKey, &$saved): void {
            if (count($run) > 1) {
                $matrices = [];
                foreach ($run as $d) {
                    $matrices[] = $d->modelMatrix;
                }
                $out[$runSlot] = new DrawMeshInstanced($run[0]->meshId, $run[0]->materialId, $matrices, castShadows: $run[0]->castShadows);
                $saved += count($run) - 1;
            }
            $run = [];
            $runSlot = -1;
            $runKey = '';
        };

        foreach ($commands as $cmd) {
            if ($cmd instanceof DrawMesh || $cmd instanceof DrawMeshInstanced) {
                $isTransparent = $transparent[$cmd->materialId] ??= (int) self::isTransparent($cmd->materialId);
                if ($isTransparent === 0) {
                    $out[] = $cmd; // opaque: drawn in another pass, does not end a run
                    continue;
                }
                if ($cmd instanceof DrawMesh && self::eligible($cmd)) {
                    $key = $cmd->meshId . '|' . $cmd->materialId . '|' . ($cmd->castShadows ? '1' : '0');
                    if ($run !== [] && $key !== $runKey) {
                        $flush();
                    }
                    if ($run === []) {
                        $runSlot = count($out);
                        $runKey = $key;
                        $out[] = $cmd; // placeholder, replaced when the run ends with more than one
                    }
                    // Members after the first leave the list: their matrices live in the batch.
                    $run[] = $cmd;
                    continue;
                }
                $flush();
                $out[] = $cmd;
                continue;
            }
            $flush();
            $out[] = $cmd;
        }
        $flush();

        return $saved > 0 ? [array_values($out), $saved] : null;
    }

    private static function isTransparent(string $materialId): bool
    {
        $material = MaterialRegistry::get($materialId);

        return $material !== null && $material->alpha < 1.0;
    }

    private static function eligible(DrawMesh $cmd): bool
    {
        return $cmd->prevModelMatrix === null
            && $cmd->params === null
            && !$cmd->excludeFromGbuffer
            && !$cmd->shadowOnly
            && self::isFlat($cmd->meshId);
    }

    private static function isFlat(string $meshId): bool
    {
        $version = MeshRegistry::version($meshId);
        $cached = self::$flatCache[$meshId] ?? null;
        if ($cached !== null && $cached[0] === $version) {
            return $cached[1];
        }
        $mesh = MeshRegistry::get($meshId);
        $flat = $mesh !== null && $mesh->facesOneWay();
        self::$flatCache[$meshId] = [$version, $flat];

        return $flat;
    }

    /** Forget the cached flatness of meshes (tests, or after re-registering a mesh id). */
    public static function clearCache(): void
    {
        self::$flatCache = [];
    }
}

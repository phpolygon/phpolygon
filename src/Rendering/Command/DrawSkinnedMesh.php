<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Mat4;

/**
 * Draws a skinned mesh ({@see \PHPolygon\Geometry\SkinnedMeshRegistry}) in one or many
 * instances, posed on the GPU: the vertex stage blends four bone matrices per vertex
 * from a palette kept in a vertex storage buffer (no texture unit, so it works on
 * 16-unit backends). Needs VIO_FEATURE_VERTEX_STORAGE; without it the renderer skips the
 * draw and the game poses on the CPU ({@see \PHPolygon\Geometry\SkinnedMeshData::skin()}).
 *
 * Instances share palettes: $paletteIndex[i] picks the palette of instance i from
 * $palettes (a crowd that plays a few baked poses keeps the list short). Opaque only; it
 * casts shadows into the cascade, spot and point shadow maps and writes the G-buffer
 * like any opaque mesh.
 */
readonly class DrawSkinnedMesh
{
    /**
     * @param list<Mat4>        $models       One world matrix per instance. The unused w of columns 0..2 may carry
     *                                        InstanceParams like any instanced draw.
     * @param list<list<float>|string> $palettes Distinct bone palettes: 16 floats per joint (SkinnedMeshData::palette()) or
     *                                        the same packed by SkinnedMeshData::packPalette().
     * @param list<int>         $paletteIndex Palette of each instance; empty = instance i uses palette i (or 0 for a single palette).
     */
    public function __construct(
        public string $meshId,
        public string $materialId,
        public array $models,
        public array $palettes,
        public array $paletteIndex = [],
        public bool $castShadows = true,
        /**
         * Last frame's palettes, same count and indexing as $palettes: the motion vectors of a temporal
         * frame (TAA, upscalers) then include the skinned motion. Empty = the current pose (no own motion).
         *
         * @var list<list<float>|string>
         */
        public array $prevPalettes = [],
        /**
         * True = hidden from the main camera's passes (opaque, prepass, transparent) but still cast into the
         * shadow maps and drawn by mirrors: a first-person player's body ({@see DrawMesh::$shadowOnly}).
         */
        public bool $shadowOnly = false,
        /** What a mirror ({@see SetPlanarMirror::$layerMask}) sees this draw as; see {@see \PHPolygon\Rendering\RenderLayers}. */
        public int $layers = \PHPolygon\Rendering\RenderLayers::WORLD,
    ) {
        if ($models === [] || $palettes === []) {
            throw new \InvalidArgumentException('DrawSkinnedMesh needs at least one model and one palette');
        }
        if ($paletteIndex !== [] && count($paletteIndex) !== count($models)) {
            throw new \InvalidArgumentException('DrawSkinnedMesh: one palette index per instance');
        }
    }

    /** @param list<float>|string $palette */
    public static function single(string $meshId, string $materialId, Mat4 $model, array|string $palette, bool $castShadows = true): self
    {
        return new self($meshId, $materialId, [$model], [$palette], [], $castShadows);
    }

    public function paletteOf(int $instance): int
    {
        if ($this->paletteIndex !== []) {
            return $this->paletteIndex[$instance];
        }

        return count($this->palettes) === count($this->models) ? $instance : 0;
    }
}

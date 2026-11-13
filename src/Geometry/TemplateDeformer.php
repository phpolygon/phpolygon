<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * A template mesh shaped by a spec: first a linear blend of precomputed shape deltas ({@see MorphTargets}: one target per
 * axis of variation, a weight each), then an optional landmark warp ({@see LandmarkWarp}) that moves named points onto
 * target positions. The result is the same topology with new positions, ready for {@see CatmullClark} (or, with the
 * stencils of an earlier subdivision, {@see SubdivisionResult::positions()}).
 *
 * Immutable; deform() is pure.
 */
final readonly class TemplateDeformer
{
    public function __construct(public PolyMesh $template, public ?MorphTargets $shapes = null)
    {
    }

    /**
     * @param array<string, float> $shapeWeights morph target name => weight (unknown names are ignored)
     */
    public function deform(array $shapeWeights = [], ?LandmarkWarp $warp = null): PolyMesh
    {
        $positions = $this->template->positions;
        if ($this->shapes !== null && $shapeWeights !== []) {
            $base = new MeshData($positions, [], [], []);
            $positions = $this->shapes->apply($base, $shapeWeights)->vertices;
        }
        if ($warp !== null) {
            $positions = $warp->apply($positions);
        }

        return $this->template->withPositions($positions);
    }
}

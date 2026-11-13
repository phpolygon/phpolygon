<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * The outcome of {@see CatmullClark::subdivide()}: the smooth mesh, and how it relates to the base mesh.
 *
 * Index mapping. Base vertex i is subdivided vertex i at every level (the first vertices of a level are the previous
 * level's, moved onto the smoother surface); the new vertices (edge and face points) follow. The subdivided mesh has
 * {@see $vertexCount} position vertices; a UV seam or a hard crease splits some of them into several vertices of
 * {@see $mesh}: {@see $outputToVertex} maps each vertex of the mesh back to its position vertex.
 *
 * Stencils. Every subdivided position vertex is a fixed linear combination of base vertices (the weights sum to 1):
 * `stencilIndex[v]` lists the base vertices, `stencilWeight[v]` their weights. So anything defined on the base mesh
 * (skin weights, landmark membership, region labels, morph deltas, a warp) is carried over by
 * {@see interpolate()} - and a deformation of the base positions can be re-smoothed without subdividing again with
 * {@see positions()}. The stencils are independent of the base positions: they depend on the topology and the creases.
 *
 * Immutable.
 */
final readonly class SubdivisionResult
{
    /**
     * @param list<list<int>>   $stencilIndex
     * @param list<list<float>> $stencilWeight
     * @param list<int>         $outputToVertex
     */
    public function __construct(
        public MeshData $mesh,
        public int $levels,
        public int $baseVertexCount,
        public int $vertexCount,
        public array $stencilIndex,
        public array $stencilWeight,
        public array $outputToVertex,
    ) {
    }

    /**
     * An attribute defined per base vertex, per subdivided position vertex: the stencil combination of its values.
     *
     * @param list<float> $baseValues $components values per base vertex
     * @return list<float> $components values per subdivided position vertex
     */
    public function interpolate(array $baseValues, int $components = 1): array
    {
        if (count($baseValues) !== $this->baseVertexCount * $components) {
            throw new \InvalidArgumentException('SubdivisionResult::interpolate: expected ' . $this->baseVertexCount * $components . ' values');
        }
        $out = [];
        for ($v = 0; $v < $this->vertexCount; $v++) {
            $idx = $this->stencilIndex[$v];
            $w = $this->stencilWeight[$v];
            for ($c = 0; $c < $components; $c++) {
                $s = 0.0;
                foreach ($idx as $k => $b) {
                    $s += $w[$k] * $baseValues[$b * $components + $c];
                }
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * The same, per vertex of {@see $mesh} (UV seams and hard creases duplicate position vertices).
     *
     * @param list<float> $baseValues
     * @return list<float>
     */
    public function interpolateOutput(array $baseValues, int $components = 1): array
    {
        $perVertex = $this->interpolate($baseValues, $components);
        $out = [];
        foreach ($this->outputToVertex as $v) {
            for ($c = 0; $c < $components; $c++) {
                $out[] = $perVertex[$v * $components + $c];
            }
        }

        return $out;
    }

    /**
     * Subdivided positions for other base positions (the base mesh warped, morphed or posed), with the stencils: no
     * topology work, one sparse multiply.
     *
     * @param list<float> $basePositions xyz per base vertex
     * @return list<float> xyz per subdivided position vertex
     */
    public function positions(array $basePositions): array
    {
        return $this->interpolate($basePositions, 3);
    }

    /**
     * Positions of the vertices of {@see $mesh} for other base positions.
     *
     * @param list<float> $basePositions
     * @return list<float>
     */
    public function outputPositions(array $basePositions): array
    {
        return $this->interpolateOutput($basePositions, 3);
    }
}

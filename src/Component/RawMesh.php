<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Geometry\MeshData;

/**
 * A mesh stored as raw geometry — flat vertex/normal/uv arrays and triangle
 * indices — rather than a procedural graph. This is the persistence target for
 * meshes that were edited vertex-by-vertex in the editor or imported from a
 * file and converted once to {@see MeshData}, which have no generating graph to
 * re-evaluate.
 *
 * Pure data, so it serializes through the standard component pipeline and
 * round-trips inside a prefab/scene document. {@see toMeshData()} yields the
 * geometry to publish under {@see $meshId} for a sibling MeshRenderer.
 */
#[Serializable]
#[Category('Geometry')]
class RawMesh extends AbstractComponent
{
    /** @var list<float> flat xyz vertex positions */
    #[Property]
    public array $vertices = [];

    /** @var list<float> flat xyz vertex normals */
    #[Property]
    public array $normals = [];

    /** @var list<float> flat xy texture coordinates */
    #[Property]
    public array $uvs = [];

    /** @var list<int> triangle indices */
    #[Property]
    public array $indices = [];

    /** MeshRegistry id this mesh is published under. */
    #[Property]
    public string $meshId = '';

    public function toMeshData(): MeshData
    {
        return new MeshData($this->vertices, $this->normals, $this->uvs, $this->indices);
    }
}

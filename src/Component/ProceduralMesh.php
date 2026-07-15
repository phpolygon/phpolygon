<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * A node-graph description of a procedurally generated mesh. Pure data, so it
 * serializes through the standard component pipeline and round-trips inside a
 * prefab document. {@see \PHPolygon\Geometry\ProceduralMeshEvaluator} turns the
 * graph into a {@see \PHPolygon\Geometry\MeshData} and publishes it under
 * {@see $meshId}, which a sibling MeshRenderer then references.
 *
 * Each entry in {@see $nodes} is a plain array:
 *   [
 *     'id'     => 'trunk',          // unique within the graph
 *     'type'   => 'cylinder',       // generator/operator name (see evaluator)
 *     'params' => ['radius' => 0.3, 'height' => 4.0, 'segments' => 12],
 *     'inputs' => ['mesh' => 'someOtherNodeId'],  // slot => source node id
 *   ]
 *
 * {@see $output} names the node whose mesh is the final result; connections are
 * expressed by the `inputs` maps (this is the "node wiring" of the editor).
 */
#[Serializable]
#[Category('Geometry')]
class ProceduralMesh extends AbstractComponent
{
    /** @var list<array<string, mixed>> */
    #[Property(editorHint: 'nodegraph')]
    public array $nodes = [];

    /** Id of the node whose evaluated mesh is the graph's result. */
    #[Property]
    public string $output = '';

    /** MeshRegistry id the evaluated mesh is published under. */
    #[Property]
    public string $meshId = '';
}

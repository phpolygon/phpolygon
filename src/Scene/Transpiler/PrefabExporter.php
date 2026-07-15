<?php

declare(strict_types=1);

namespace PHPolygon\Scene\Transpiler;

use PHPolygon\Component\NameTag;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\Serializer\AttributeSerializer;
use PHPolygon\ECS\Serializer\SerializerInterface;
use PHPolygon\ECS\World;

/**
 * Serialize a live entity subtree into a reusable {@see PrefabDocument} — the
 * reverse of {@see PrefabInstantiator}.
 *
 * Starting at the root entity, it walks Transform3D child links and emits a
 * nested `{ name, components, children }` tree. An entity's NameTag becomes the
 * node `name` (not duplicated as a component), and Transform3D's runtime
 * parent/child ids are stripped — the hierarchy is captured by the nesting so
 * the prefab is position-/id-independent.
 */
final class PrefabExporter
{
    private SerializerInterface $serializer;

    public function __construct(?SerializerInterface $serializer = null)
    {
        $this->serializer = $serializer ?? new AttributeSerializer();
    }

    /**
     * @return array<string, mixed> prefab document (pass to json_encode)
     */
    public function export(World $world, int $rootEntityId, ?string $name = null): array
    {
        $root = $this->exportNode($world, $rootEntityId);

        return [
            '_version' => PrefabDocument::VERSION,
            'name' => $name ?? (is_string($root['name']) ? $root['name'] : 'prefab'),
            'root' => $root,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportNode(World $world, int $entityId): array
    {
        $name = 'entity_' . $entityId;
        $components = [];
        $childIds = [];

        foreach ($world->getEntityComponents($entityId) as $component) {
            if ($component instanceof NameTag) {
                $name = $component->name;
                continue;
            }

            $data = $this->serializer->toArray($component);

            if ($component instanceof Transform3D) {
                $childIds = $component->childEntityIds;
                // Hierarchy is structural in the prefab, not id-based.
                unset($data['parentEntityId'], $data['childEntityIds']);
            }

            $components[] = $data;
        }

        $node = [
            'name' => $name,
            'components' => $components,
        ];

        $children = [];
        foreach ($childIds as $childId) {
            if ($world->isAlive($childId)) {
                $children[] = $this->exportNode($world, $childId);
            }
        }
        if ($children !== []) {
            $node['children'] = $children;
        }

        return $node;
    }
}

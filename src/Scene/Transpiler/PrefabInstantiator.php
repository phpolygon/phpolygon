<?php

declare(strict_types=1);

namespace PHPolygon\Scene\Transpiler;

use PHPolygon\Component\NameTag;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\ComponentInterface;
use PHPolygon\ECS\Serializer\AttributeSerializer;
use PHPolygon\ECS\Serializer\SerializerInterface;
use PHPolygon\ECS\World;
use ReflectionClass;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Instantiate a {@see PrefabDocument} into a live ECS {@see World} — the
 * reverse of {@see PrefabExporter}, and the piece {@see WorldImporter} does not
 * cover (it applies only a flat entity list and ignores `children`).
 *
 * Each node becomes an entity with its deserialized components; the tree's
 * parent/child links are rewired with fresh entity ids through Transform3D so
 * a prefab can be stamped in any number of times. Pass a $namePrefix to keep
 * NameTags unique across copies.
 */
final class PrefabInstantiator
{
    private SerializerInterface $serializer;

    public function __construct(?SerializerInterface $serializer = null)
    {
        $this->serializer = $serializer ?? new AttributeSerializer();
    }

    /**
     * @param array<string, mixed> $prefab
     * @return int the root entity id
     */
    public function instantiate(World $world, array $prefab, string $namePrefix = ''): int
    {
        PrefabDocument::validate($prefab);

        /** @var array<string, mixed> $root */
        $root = $prefab['root'];

        return $this->instantiateNode($world, $root, null, $namePrefix);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function instantiateNode(World $world, array $node, ?int $parentEntityId, string $namePrefix): int
    {
        $entity = $world->createEntity();
        $name = is_string($node['name'] ?? null) ? $node['name'] : 'entity_' . $entity->id;

        $transform = null;
        /** @var list<array<string, mixed>> $components */
        $components = is_array($node['components'] ?? null) ? $node['components'] : [];
        foreach ($components as $componentData) {
            $class = $componentData['_class'] ?? null;
            if (!is_string($class) || !class_exists($class) || !$this->isSerializable($class)) {
                continue;
            }

            $component = $this->serializer->fromArray($componentData, $class);
            if (!$component instanceof ComponentInterface) {
                continue;
            }

            // Name identity comes from the node, not a duplicated NameTag.
            if ($component instanceof NameTag) {
                continue;
            }

            if ($component instanceof Transform3D) {
                // Drop any stale ids; the live hierarchy is rewired below.
                $component->parentEntityId = null;
                $component->childEntityIds = [];
                $transform = $component;
            }

            $entity->attach($component);
        }

        $entity->attach(new NameTag($namePrefix . $name));

        // Rewire the live parent/child link (only possible when both ends have
        // a Transform3D to carry the relationship).
        if ($parentEntityId !== null && $transform !== null) {
            $parentTransform = $world->tryGetComponent($parentEntityId, Transform3D::class);
            if ($parentTransform instanceof Transform3D) {
                $parentTransform->addChild($transform, $entity->id, $parentEntityId);
            }
        }

        /** @var list<mixed> $children */
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        foreach ($children as $childNode) {
            if (is_array($childNode)) {
                /** @var array<string, mixed> $childNode */
                $this->instantiateNode($world, $childNode, $entity->id, $namePrefix);
            }
        }

        return $entity->id;
    }

    /**
     * @param class-string $class
     */
    private function isSerializable(string $class): bool
    {
        return (new ReflectionClass($class))->getAttributes(Serializable::class) !== [];
    }
}

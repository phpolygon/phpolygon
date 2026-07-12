<?php

declare(strict_types=1);

namespace PHPolygon\Scene\Transpiler;

use PHPolygon\Component\NameTag;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\ECS\ComponentInterface;
use PHPolygon\ECS\Serializer\AttributeSerializer;
use PHPolygon\ECS\Serializer\SerializerInterface;
use PHPolygon\ECS\World;
use ReflectionClass;

/**
 * Applies an editor scene-JSON snapshot to a live ECS {@see World} — the
 * reverse of {@see WorldExporter}, i.e. the "editor → game" direction.
 *
 * Spawns each described entity and attaches its deserialized components. It is
 * additive: existing entities are left untouched, so a game can decide whether
 * to {@see World::clear()} first (full replace) or merge into the running world.
 * Entities keep a {@see NameTag} so a later export round-trips their identity.
 */
final class WorldImporter
{
    private SerializerInterface $serializer;

    public function __construct(?SerializerInterface $serializer = null)
    {
        $this->serializer = $serializer ?? new AttributeSerializer;
    }

    /**
     * @param  array<string, mixed>  $data  Scene-JSON snapshot (from the editor / WorldExporter).
     * @return array<string, int>  Created entities keyed by name.
     */
    public function apply(World $world, array $data): array
    {
        $created = [];
        $entities = is_array($data['entities'] ?? null) ? $data['entities'] : [];

        foreach ($entities as $entityData) {
            if (! is_array($entityData)) {
                continue;
            }
            $entity = $world->createEntity();
            $name = is_string($entityData['name'] ?? null) ? $entityData['name'] : 'entity_'.$entity->id;

            $hasNameTag = false;
            $components = is_array($entityData['components'] ?? null) ? $entityData['components'] : [];
            foreach ($components as $componentData) {
                if (! is_array($componentData)) {
                    continue;
                }
                $class = $componentData['_class'] ?? null;
                if (! is_string($class) || ! class_exists($class) || ! $this->isSerializable($class)) {
                    continue;
                }
                $component = $this->serializer->fromArray($componentData, $class);
                if ($component instanceof ComponentInterface) {
                    $entity->attach($component);
                    $hasNameTag = $hasNameTag || $component instanceof NameTag;
                }
            }

            // Preserve identity so a re-export names the entity the same way.
            if (! $hasNameTag && ! str_starts_with($name, 'entity_')) {
                $entity->attach(new NameTag($name));
            }

            $created[$name] = $entity->id;
        }

        return $created;
    }

    /**
     * @param  class-string  $class
     */
    private function isSerializable(string $class): bool
    {
        return (new ReflectionClass($class))->getAttributes(Serializable::class) !== [];
    }
}

<?php

declare(strict_types=1);

namespace PHPolygon\Scene\Transpiler;

use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\ECS\ComponentInterface;
use PHPolygon\ECS\Serializer\AttributeSerializer;
use PHPolygon\ECS\Serializer\SerializerInterface;
use PHPolygon\Scene\EntityDeclaration;
use PHPolygon\Scene\Prefab;
use PHPolygon\Scene\PrefabInterface;
use PHPolygon\Scene\PrefabRegistry;
use PHPolygon\Scene\SceneBuilder;
use ReflectionClass;

/**
 * Loads an editor scene-JSON document into a live scene under construction — the
 * "authored JSON → running game" direction — WITHOUT inlining prefab geometry.
 *
 * For a prefab-tagged entity ({@see EntityDeclaration::getPrefabSource()}) this
 * loader re-runs the prefab's PHP build() to produce geometry: the entity's
 * authored components are fed to the prefab as build() INPUT (via
 * {@see Prefab::withAuthored()}) so a design-variant component can select which
 * geometry/material the prefab assembles, and are then applied as post-build
 * overrides ({@see ComponentOverrides}) so the authored data (transform, gameplay
 * state) lives on the resulting entity and round-trips. Geometry itself is never
 * read from the JSON — it always comes from PHP.
 *
 * For a plain (non-prefab) entity it inline-deserializes components exactly like
 * {@see WorldImporter}.
 *
 * It loads into a {@see SceneBuilder} rather than straight into a World so the
 * loaded entities go through the normal materialize() path (NameTag, hierarchy)
 * alongside the rest of a PHP scene's build(). Loaded entities are DISCRETE — the
 * loader never routes them through a build-time instancing collector; a runtime
 * bake system may collapse them afterwards.
 */
final class JsonSceneLoader
{
    private SerializerInterface $serializer;

    public function __construct(?SerializerInterface $serializer = null)
    {
        $this->serializer = $serializer ?? new AttributeSerializer;
    }

    /**
     * @param array<string, mixed> $sceneJson Scene-JSON document ({ entities: [...] }).
     * @return list<EntityDeclaration> The declarations added to $builder.
     */
    public function load(SceneBuilder $builder, array $sceneJson, PrefabRegistry $registry): array
    {
        /** @var list<array<string, mixed>> $entities */
        $entities = is_array($sceneJson['entities'] ?? null) ? $sceneJson['entities'] : [];

        $result = [];
        foreach ($entities as $entityData) {
            $result[] = $this->loadEntity($builder, $entityData, $registry);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $entityData
     */
    private function loadEntity(SceneBuilder $builder, array $entityData, PrefabRegistry $registry): EntityDeclaration
    {
        $name = is_string($entityData['name'] ?? null) ? $entityData['name'] : null;
        $components = $this->deserializeComponents($entityData['components'] ?? null);

        $prefabRef = is_string($entityData['prefab'] ?? null) ? $entityData['prefab'] : null;

        if ($prefabRef !== null) {
            $prefab = $this->resolvePrefab($prefabRef, $registry);

            // The placement Transform3D is fed to the prefab as build() INPUT
            // (position/rotation/scale) — a prefab's build() decides how it and
            // any child parts are transformed from that placement, so applying
            // the transform as a blind override would fight prefabs that derive
            // part transforms (e.g. flat sibling parts in world space). The rest
            // are authored build inputs AND post-build overrides.
            $transform = null;
            $overrides = [];
            foreach ($components as $component) {
                if ($component instanceof Transform3D) {
                    $transform = $component;
                } else {
                    $overrides[] = $component;
                }
            }

            if ($prefab instanceof Prefab) {
                if ($transform !== null) {
                    // A partially-authored Transform3D (e.g. position only) leaves
                    // the other typed properties uninitialized, so feed each only
                    // when it is set.
                    if (isset($transform->position)) {
                        $prefab->at($transform->position);
                    }
                    if (isset($transform->rotation)) {
                        $prefab->rotated($transform->rotation);
                    }
                    if (isset($transform->scale)) {
                        $prefab->scaled($transform->scale);
                    }
                }
                if ($overrides !== []) {
                    // Authored components are build() INPUT (e.g. a variant
                    // selecting geometry) — geometry comes from PHP, not JSON.
                    $prefab->withAuthored(...$overrides);
                }
            }

            $decl = $builder->prefabInstance($prefab, $name);

            // Authored non-transform data (gameplay state, the variant marker)
            // wins over any build() default and round-trips.
            ComponentOverrides::applyByClass($decl, $overrides);

            // If build() produced no transform, fall back to the authored one so
            // the entity is never left unpositioned.
            if ($transform !== null && !$this->hasComponent($decl, Transform3D::class)) {
                $decl->with($transform);
            }

            return $decl;
        }

        // Plain entity: inline-deserialize like WorldImporter.
        $decl = $builder->entity($name ?? 'entity');
        foreach ($components as $component) {
            $decl->with($component);
        }

        return $decl;
    }

    /**
     * Resolve a prefab reference that may be either a registered name or a
     * fully-qualified prefab class-string (what the transpiler stores).
     */
    private function resolvePrefab(string $ref, PrefabRegistry $registry): PrefabInterface
    {
        if ($registry->has($ref)) {
            return $registry->create($ref);
        }

        if (class_exists($ref) && is_subclass_of($ref, PrefabInterface::class)) {
            /** @var PrefabInterface */
            return new $ref();
        }

        throw new \RuntimeException("Prefab reference '{$ref}' is neither a registered name nor a loadable prefab class.");
    }

    /**
     * @param mixed $raw
     * @return list<ComponentInterface>
     */
    private function deserializeComponents(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        /** @var list<array<string, mixed>> $raw */
        $components = [];
        foreach ($raw as $componentData) {
            $class = $componentData['_class'] ?? null;
            if (!is_string($class) || !class_exists($class) || !$this->isSerializable($class)) {
                continue;
            }
            $component = $this->serializer->fromArray($componentData, $class);
            if ($component instanceof ComponentInterface) {
                $components[] = $component;
            }
        }

        return $components;
    }

    /**
     * @param class-string $class
     */
    private function hasComponent(EntityDeclaration $decl, string $class): bool
    {
        foreach ($decl->getComponents() as $component) {
            if ($component instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param class-string $class
     */
    private function isSerializable(string $class): bool
    {
        return (new ReflectionClass($class))->getAttributes(Serializable::class) !== [];
    }
}

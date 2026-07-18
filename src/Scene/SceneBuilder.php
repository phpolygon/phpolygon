<?php

declare(strict_types=1);

namespace PHPolygon\Scene;

use PHPolygon\Component\NameTag;
use PHPolygon\Component\Transform2D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\ComponentInterface;
use PHPolygon\ECS\Entity;
use PHPolygon\ECS\World;

class SceneBuilder
{
    /** @var list<EntityDeclaration> */
    private array $declarations = [];

    private ?PrefabRegistry $prefabRegistry = null;

    public function entity(string $name): EntityDeclaration
    {
        $decl = new EntityDeclaration($name, $this);
        $this->declarations[] = $decl;
        return $decl;
    }

    /**
     * @param class-string<PrefabInterface> $prefabClass
     */
    public function prefab(string $prefabClass, string $name): EntityDeclaration
    {
        /** @var PrefabInterface $prefab */
        $prefab = new $prefabClass();
        $decl = $prefab->build($this);
        $decl->setPrefabSource($prefabClass);
        return $decl;
    }

    /**
     * Attach a prefab registry so {@see prefabByName()} can resolve prefabs by
     * their registered name (as opposed to {@see prefab()}, which is handed a
     * class-string directly).
     */
    public function setPrefabRegistry(?PrefabRegistry $registry): void
    {
        $this->prefabRegistry = $registry;
    }

    public function getPrefabRegistry(): ?PrefabRegistry
    {
        return $this->prefabRegistry;
    }

    /**
     * Resolve a prefab by its registered name (via the attached
     * {@see PrefabRegistry}), build it under $entityName, and tag the resulting
     * declaration with its source class so the transpiler can round-trip it.
     */
    public function prefabByName(string $name, string $entityName): EntityDeclaration
    {
        if ($this->prefabRegistry === null) {
            throw new \LogicException(
                'SceneBuilder has no PrefabRegistry; call setPrefabRegistry() before prefabByName().'
            );
        }

        $prefab = $this->prefabRegistry->create($name);

        return $this->prefabInstance($prefab, $entityName);
    }

    /**
     * Build an already-constructed prefab instance under $entityName and tag the
     * resulting declaration with its source class.
     *
     * This is the seam the JSON scene loader uses: it constructs the prefab,
     * feeds it authored components via {@see Prefab::withAuthored()} and a name
     * via {@see Prefab::named()}, then hands the configured instance here so its
     * build() runs against this builder. Geometry therefore always comes from
     * the prefab's PHP build(), never from stored JSON.
     */
    public function prefabInstance(PrefabInterface $prefab, ?string $entityName = null): EntityDeclaration
    {
        if ($prefab instanceof Prefab) {
            if ($entityName !== null) {
                $prefab->named($entityName);
            }
            $prefab->bindBuilder($this);
        }

        $startIndex = count($this->declarations);
        $decl = $prefab->build($this);
        $decl->setPrefabSource($prefab::class);

        // Flag every OTHER declaration build() emitted (the geometry parts) as
        // prefab-generated so the transpiler serializes only the anchor reference
        // and regenerates the parts from build() on load. Runtime materialize()
        // ignores the flag, so the parts still exist in the live world.
        $count = count($this->declarations);
        for ($i = $startIndex; $i < $count; $i++) {
            if ($this->declarations[$i] !== $decl) {
                $this->declarations[$i]->markGeneratedByPrefab();
            }
        }

        // Record the authored INPUT (placement + the components fed via
        // withAuthored) so serialization stores the input, not build()'s derived
        // output. This is what makes the round-trip clean: reload feeds the same
        // input back into build().
        if ($prefab instanceof Prefab) {
            $authored = [
                new Transform3D($prefab->getPosition(), $prefab->getRotation(), $prefab->getScale()),
                ...array_values(array_filter(
                    $prefab->getAuthored(),
                    static fn ($c): bool => !$c instanceof Transform3D,
                )),
            ];
            $decl->setPrefabAuthored($authored);
        }

        return $decl;
    }

    /**
     * Bind a Prefab instance to this builder so its modifier chain can finish
     * with `->place(...)`. Returns the same instance for fluent chaining.
     *
     * @template T of Prefab
     * @param T $prefab
     * @return T
     */
    public function spawn(Prefab $prefab): Prefab
    {
        $prefab->bindBuilder($this);
        return $prefab;
    }

    /** @return list<EntityDeclaration> */
    public function getDeclarations(): array
    {
        return $this->declarations;
    }

    /**
     * Materialize all declarations into a World, returning created entity IDs.
     *
     * @return array<string, int> Map of declaration name => entity ID
     */
    public function materialize(World $world): array
    {
        $map = [];
        foreach ($this->declarations as $decl) {
            $this->materializeDeclaration($decl, $world, null, $map);
        }
        return $map;
    }

    /**
     * @param array<string, int> $map
     */
    private function materializeDeclaration(
        EntityDeclaration $decl,
        World $world,
        ?int $parentId,
        array &$map,
    ): int {
        $entity = $world->createEntity();
        $id = $entity->id;
        $map[$decl->getName()] = $id;

        // Auto-attach NameTag
        $hasNameTag = false;
        foreach ($decl->getComponents() as $component) {
            if ($component instanceof NameTag) {
                $hasNameTag = true;
            }
            $entity->attach(clone $component);
        }
        if (!$hasNameTag) {
            $entity->attach(new NameTag($decl->getName()));
        }

        // Wire up parent/child links on whichever transform component exists.
        if ($parentId !== null) {
            $transform2D = $world->tryGetComponent($id, Transform2D::class);
            if ($transform2D instanceof Transform2D) {
                $transform2D->parentEntityId = $parentId;
            }
            $parentTransform2D = $world->tryGetComponent($parentId, Transform2D::class);
            if ($parentTransform2D instanceof Transform2D) {
                $parentTransform2D->childEntityIds[] = $id;
            }

            $transform3D = $world->tryGetComponent($id, Transform3D::class);
            if ($transform3D instanceof Transform3D) {
                $transform3D->parentEntityId = $parentId;
            }
            $parentTransform3D = $world->tryGetComponent($parentId, Transform3D::class);
            if ($parentTransform3D instanceof Transform3D) {
                $parentTransform3D->childEntityIds[] = $id;
            }
        }

        // Materialize children
        foreach ($decl->getChildren() as $childDecl) {
            $this->materializeDeclaration($childDecl, $world, $id, $map);
        }

        return $id;
    }
}

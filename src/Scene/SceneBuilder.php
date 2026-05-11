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

<?php

declare(strict_types=1);

namespace PHPolygon\ECS;

use PHPolygon\Component\Transform2D;
use PHPolygon\Component\Transform3D;
use PHPolygon\Runtime\PerfProfiler;
use RuntimeException;

class World
{
    private int $nextEntityId = 0;

    /** @var list<int> */
    private array $freeList = [];

    /** @var array<int, bool> */
    private array $alive = [];

    /** @var array<class-string, array<int, ComponentInterface>> */
    private array $components = [];

    /** @var array<int, array<class-string, ComponentInterface>> */
    private array $entityComponents = [];

    /**
     * Parked components of dormant entities. Same shape as $components, but the
     * per-tick iteration surface (componentPool / componentEntities / query)
     * reads only $components — so dormant entities are excluded from all system
     * iteration while staying fully resident. $entityComponents is NOT split, so
     * single-entity access (getComponent / tryGetComponent / hasComponent) keeps
     * working for a dormant entity. See setEntityDormant().
     *
     * @var array<class-string, array<int, ComponentInterface>>
     */
    private array $dormantComponents = [];

    /** @var array<int, bool> Entities currently dormant (resident, iteration-excluded). */
    private array $dormant = [];

    /** @var list<SystemInterface> */
    private array $systems = [];

    /** @var array<int, SystemPhase> Phase per system (index-aligned with $systems) */
    private array $systemPhases = [];

    /** @var array<int, Entity> */
    private array $entityCache = [];

    /**
     * Cached PerfProfiler section name per system, indexed by spl_object_id.
     * Avoids per-frame Reflection while keeping system-specific labels.
     *
     * @var array<int, string>
     */
    private array $systemPerfNames = [];

    public function createEntity(): Entity
    {
        if (!empty($this->freeList)) {
            $id = array_pop($this->freeList);
        } else {
            $id = ++$this->nextEntityId;
        }

        $this->alive[$id] = true;
        $this->entityComponents[$id] = [];

        $entity = new Entity($id, $this);
        $this->entityCache[$id] = $entity;
        return $entity;
    }

    public function destroyEntity(int $id): void
    {
        if (!isset($this->alive[$id])) {
            return;
        }

        // Cascade destroy children via Transform2D hierarchy
        $transform = $this->tryGetComponent($id, Transform2D::class);
        if ($transform instanceof Transform2D) {
            foreach ($transform->childEntityIds as $childId) {
                $this->destroyEntity($childId);
            }

            // Remove from parent's child list
            if ($transform->parentEntityId !== null && $this->isAlive($transform->parentEntityId)) {
                $parentTransform = $this->tryGetComponent($transform->parentEntityId, Transform2D::class);
                if ($parentTransform instanceof Transform2D) {
                    $parentTransform->childEntityIds = array_values(
                        array_filter($parentTransform->childEntityIds, fn(int $cid) => $cid !== $id)
                    );
                }
            }
        }

        // Cascade destroy children via Transform3D hierarchy
        $transform3D = $this->tryGetComponent($id, Transform3D::class);
        if ($transform3D instanceof Transform3D) {
            foreach ($transform3D->childEntityIds as $childId) {
                $this->destroyEntity($childId);
            }

            if ($transform3D->parentEntityId !== null && $this->isAlive($transform3D->parentEntityId)) {
                $parentTransform3D = $this->tryGetComponent($transform3D->parentEntityId, Transform3D::class);
                if ($parentTransform3D instanceof Transform3D) {
                    $parentTransform3D->removeChild($transform3D, $id);
                }
            }
        }

        foreach ($this->entityComponents[$id] as $class => $component) {
            $component->onDetach($this->entity($id));
            unset($this->components[$class][$id], $this->dormantComponents[$class][$id]);
        }

        unset($this->entityComponents[$id], $this->alive[$id], $this->entityCache[$id], $this->dormant[$id]);
        $this->freeList[] = $id;
    }

    public function entity(int $id): Entity
    {
        if (isset($this->entityCache[$id])) {
            return $this->entityCache[$id];
        }

        if (!isset($this->alive[$id])) {
            throw new RuntimeException("Entity {$id} does not exist");
        }

        $entity = new Entity($id, $this);
        $this->entityCache[$id] = $entity;
        return $entity;
    }

    public function isAlive(int $id): bool
    {
        return isset($this->alive[$id]);
    }

    public function entityCount(): int
    {
        return count($this->alive);
    }

    // --- Component operations ---

    public function attachComponent(int $entityId, ComponentInterface $component): void
    {
        if (!isset($this->alive[$entityId])) {
            throw new RuntimeException("Entity {$entityId} does not exist");
        }

        $class = get_class($component);
        // Attaching to a dormant entity must keep its components parked, not leak
        // one into the active iteration pool (the entity stays fully dormant).
        if (isset($this->dormant[$entityId])) {
            $this->dormantComponents[$class][$entityId] = $component;
        } else {
            $this->components[$class][$entityId] = $component;
        }
        $this->entityComponents[$entityId][$class] = $component;

        $component->onAttach($this->entity($entityId));
    }

    public function detachComponent(int $entityId, string $componentClass): void
    {
        if (!isset($this->entityComponents[$entityId][$componentClass])) {
            return;
        }

        $component = $this->entityComponents[$entityId][$componentClass];
        $component->onDetach($this->entity($entityId));

        // The component lives in exactly one of the two pools depending on
        // dormancy — clear both to be safe.
        unset($this->components[$componentClass][$entityId]);
        unset($this->dormantComponents[$componentClass][$entityId]);
        unset($this->entityComponents[$entityId][$componentClass]);
    }

    /**
     * @template T of ComponentInterface
     * @param class-string<T> $componentClass
     * @return T
     */
    public function getComponent(int $entityId, string $componentClass): ComponentInterface
    {
        if (!isset($this->entityComponents[$entityId][$componentClass])) {
            throw new RuntimeException(
                "Entity {$entityId} does not have component {$componentClass}"
            );
        }

        /** @var T */
        return $this->entityComponents[$entityId][$componentClass];
    }

    /**
     * @template T of ComponentInterface
     * @param class-string<T> $componentClass
     * @return T|null
     */
    public function tryGetComponent(int $entityId, string $componentClass): ?ComponentInterface
    {
        /** @var T|null */
        return $this->entityComponents[$entityId][$componentClass] ?? null;
    }

    public function hasComponent(int $entityId, string $componentClass): bool
    {
        return isset($this->entityComponents[$entityId][$componentClass]);
    }

    /**
     * @return array<class-string, ComponentInterface>
     */
    public function getEntityComponents(int $entityId): array
    {
        return $this->entityComponents[$entityId] ?? [];
    }

    public function componentCount(string $componentClass): int
    {
        return count($this->components[$componentClass] ?? []);
    }

    /**
     * @return list<int>
     */
    public function componentEntities(string $componentClass): array
    {
        return array_keys($this->components[$componentClass] ?? []);
    }

    /**
     * Direct read access to a component pool, keyed by entity id. Lets hot
     * single-component systems iterate without the per-entity generator +
     * Entity-wrapper + get() overhead of query(). The returned array must be
     * treated as read-only structure (mutate the components, not the array).
     *
     * @return array<int, ComponentInterface>
     */
    public function componentPool(string $componentClass): array
    {
        return $this->components[$componentClass] ?? [];
    }

    // --- Dormancy ---

    /**
     * Park (or wake) an entity: its components move between the active iteration
     * pool and a parked store, so all per-tick systems (which iterate via
     * componentPool / query) skip a dormant entity, while it stays fully resident
     * and alive. Single-entity access (get/tryGet/hasComponent) is unaffected.
     *
     * Intended for activation of large spatial groups (e.g. an off-screen region
     * kept loaded but not processed). The CALLER must toggle whole Transform
     * subtrees together — a dormant child under an active parent (or vice-versa)
     * produces stale/mis-parented transforms, since the parent's hierarchy walk
     * still resolves the child via the intact single-entity index.
     *
     * O(components on the entity). No onAttach/onDetach is fired — the components
     * stay attached, only their iteration visibility changes.
     */
    public function setEntityDormant(int $entityId, bool $dormant): void
    {
        if (!isset($this->alive[$entityId])) {
            return;
        }
        $isDormant = isset($this->dormant[$entityId]);
        if ($dormant === $isDormant) {
            return;
        }

        if ($dormant) {
            foreach ($this->entityComponents[$entityId] as $class => $component) {
                $this->dormantComponents[$class][$entityId] = $component;
                unset($this->components[$class][$entityId]);
            }
            $this->dormant[$entityId] = true;
        } else {
            foreach ($this->entityComponents[$entityId] as $class => $component) {
                $this->components[$class][$entityId] = $component;
                unset($this->dormantComponents[$class][$entityId]);
            }
            unset($this->dormant[$entityId]);
        }
    }

    /**
     * Bulk form of {@see setEntityDormant()} — the entry point for toggling a
     * whole region's entity-id set in one go.
     *
     * @param iterable<int> $entityIds
     */
    public function setEntitiesDormant(iterable $entityIds, bool $dormant): void
    {
        foreach ($entityIds as $entityId) {
            $this->setEntityDormant($entityId, $dormant);
        }
    }

    public function isDormant(int $entityId): bool
    {
        return isset($this->dormant[$entityId]);
    }

    // --- Queries ---

    /**
     * @param class-string<ComponentInterface> ...$componentClasses
     */
    public function query(string ...$componentClasses): EntityQuery
    {
        return new EntityQuery($this, ...$componentClasses);
    }

    // --- Systems ---

    public function addSystem(SystemInterface $system, SystemPhase $phase = SystemPhase::MainThread): void
    {
        $this->systems[] = $system;
        $this->systemPhases[count($this->systems) - 1] = $phase;
        $shortName = (new \ReflectionClass($system))->getShortName();
        $this->systemPerfNames[\spl_object_id($system)] = 'ecs.system.' . $shortName;
        $system->register($this);
    }

    public function removeSystem(SystemInterface $system): void
    {
        $index = array_search($system, $this->systems, true);
        if ($index !== false) {
            $system->unregister($this);
            array_splice($this->systems, $index, 1);
            unset($this->systemPhases[$index]);
            unset($this->systemPerfNames[\spl_object_id($system)]);
            // Re-index phases
            $this->systemPhases = array_values($this->systemPhases);
        }
    }

    /**
     * Update all systems sequentially (single-threaded mode, backward compatible).
     */
    public function update(float $dt): void
    {
        PerfProfiler::begin('ecs.update');
        foreach ($this->systems as $system) {
            PerfProfiler::begin($this->systemPerfNames[\spl_object_id($system)] ?? 'ecs.system.unknown');
            $system->update($this, $dt);
            PerfProfiler::end();
        }
        PerfProfiler::end();
    }

    /**
     * Update only MainThread-phase systems. Used in pipelined mode.
     */
    public function updateMainThread(float $dt): void
    {
        PerfProfiler::begin('ecs.update');
        foreach ($this->systems as $i => $system) {
            if (($this->systemPhases[$i] ?? SystemPhase::MainThread) === SystemPhase::MainThread) {
                PerfProfiler::begin($this->systemPerfNames[\spl_object_id($system)] ?? 'ecs.system.unknown');
                $system->update($this, $dt);
                PerfProfiler::end();
            }
        }
        PerfProfiler::end();
    }

    /**
     * Update only PostThread-phase systems. Called after thread results are applied.
     */
    public function updatePostThread(float $dt): void
    {
        PerfProfiler::begin('ecs.update.post');
        foreach ($this->systems as $i => $system) {
            if (($this->systemPhases[$i] ?? SystemPhase::MainThread) === SystemPhase::PostThread) {
                PerfProfiler::begin($this->systemPerfNames[\spl_object_id($system)] ?? 'ecs.system.unknown');
                $system->update($this, $dt);
                PerfProfiler::end();
            }
        }
        PerfProfiler::end();
    }

    public function render(): void
    {
        PerfProfiler::begin('ecs.render');
        foreach ($this->systems as $system) {
            PerfProfiler::begin(($this->systemPerfNames[\spl_object_id($system)] ?? 'ecs.system.unknown') . '.render');
            $system->render($this);
            PerfProfiler::end();
        }
        PerfProfiler::end();
    }

    /** @return list<SystemInterface> */
    public function getSystems(): array
    {
        return $this->systems;
    }

    public function clear(): void
    {
        // Destroy all entities (triggers onDetach)
        foreach (array_keys($this->alive) as $id) {
            $this->destroyEntity($id);
        }

        $this->nextEntityId = 0;
        $this->freeList = [];
        $this->entityCache = [];
        $this->dormantComponents = [];
        $this->dormant = [];

        // Give every system a chance to drop per-entity-id caches. Without this
        // hook, caches keyed on int ids associate stale state with whichever
        // entity next reuses that id (Transform3DSystem's "not dirty" check,
        // Renderer3DSystem's spatial bin lookup, etc.).
        foreach ($this->systems as $system) {
            if ($system instanceof AbstractSystem) {
                $system->onWorldClear($this);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace PHPolygon\ECS;

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

    /** @var list<SystemInterface> */
    private array $systems = [];

    /** @var array<int, Entity> */
    private array $entityCache = [];

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

        foreach ($this->entityComponents[$id] as $class => $component) {
            $component->onDetach($this->entity($id));
            unset($this->components[$class][$id]);
        }

        unset($this->entityComponents[$id], $this->alive[$id], $this->entityCache[$id]);
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
        $this->components[$class][$entityId] = $component;
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

        unset($this->components[$componentClass][$entityId]);
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

    // --- Queries ---

    /**
     * @param class-string<ComponentInterface> ...$componentClasses
     */
    public function query(string ...$componentClasses): EntityQuery
    {
        return new EntityQuery($this, ...$componentClasses);
    }

    // --- Systems ---

    public function addSystem(SystemInterface $system): void
    {
        $this->systems[] = $system;
        $system->register($this);
    }

    public function removeSystem(SystemInterface $system): void
    {
        $index = array_search($system, $this->systems, true);
        if ($index !== false) {
            $system->unregister($this);
            array_splice($this->systems, $index, 1);
        }
    }

    public function update(float $dt): void
    {
        foreach ($this->systems as $system) {
            $system->update($this, $dt);
        }
    }

    public function render(): void
    {
        foreach ($this->systems as $system) {
            $system->render($this);
        }
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
    }
}

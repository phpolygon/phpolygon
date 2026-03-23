<?php

declare(strict_types=1);

namespace PHPolygon\ECS;

use Generator;

/**
 * Iterates over entities that have ALL of the required components.
 *
 * @implements \IteratorAggregate<int, Entity>
 */
class EntityQuery implements \IteratorAggregate
{
    /** @var list<class-string<ComponentInterface>> */
    private array $required;

    /**
     * @param World $world
     * @param class-string<ComponentInterface> ...$componentClasses
     */
    public function __construct(
        private readonly World $world,
        string ...$componentClasses,
    ) {
        $this->required = array_values($componentClasses);
    }

    /**
     * @return Generator<int, Entity>
     */
    public function getIterator(): Generator
    {
        if (empty($this->required)) {
            return;
        }

        // Iterate the smallest component pool for efficiency
        $smallest = $this->required[0];
        $smallestCount = $this->world->componentCount($smallest);

        for ($i = 1; $i < count($this->required); $i++) {
            $count = $this->world->componentCount($this->required[$i]);
            if ($count < $smallestCount) {
                $smallest = $this->required[$i];
                $smallestCount = $count;
            }
        }

        foreach ($this->world->componentEntities($smallest) as $entityId) {
            $hasAll = true;
            foreach ($this->required as $class) {
                if ($class !== $smallest && !$this->world->hasComponent($entityId, $class)) {
                    $hasAll = false;
                    break;
                }
            }
            if ($hasAll) {
                yield $entityId => $this->world->entity($entityId);
            }
        }
    }

    public function count(): int
    {
        $count = 0;
        foreach ($this as $_) {
            $count++;
        }
        return $count;
    }

    public function first(): ?Entity
    {
        foreach ($this as $entity) {
            return $entity;
        }
        return null;
    }

    /** @return list<Entity> */
    public function toArray(): array
    {
        $result = [];
        foreach ($this as $entity) {
            $result[] = $entity;
        }
        return $result;
    }
}

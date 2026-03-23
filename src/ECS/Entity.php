<?php

declare(strict_types=1);

namespace PHPolygon\ECS;

class Entity
{
    public function __construct(
        public readonly int $id,
        private readonly World $world,
    ) {}

    public function attach(ComponentInterface $component): self
    {
        $this->world->attachComponent($this->id, $component);
        return $this;
    }

    public function detach(string $componentClass): self
    {
        $this->world->detachComponent($this->id, $componentClass);
        return $this;
    }

    /**
     * @template T of ComponentInterface
     * @param class-string<T> $componentClass
     * @return T
     */
    public function get(string $componentClass): ComponentInterface
    {
        return $this->world->getComponent($this->id, $componentClass);
    }

    /**
     * @template T of ComponentInterface
     * @param class-string<T> $componentClass
     * @return T|null
     */
    public function tryGet(string $componentClass): ?ComponentInterface
    {
        return $this->world->tryGetComponent($this->id, $componentClass);
    }

    public function has(string $componentClass): bool
    {
        return $this->world->hasComponent($this->id, $componentClass);
    }

    public function destroy(): void
    {
        $this->world->destroyEntity($this->id);
    }

    public function isAlive(): bool
    {
        return $this->world->isAlive($this->id);
    }
}

<?php

declare(strict_types=1);

namespace PHPolygon\Scene;

use PHPolygon\ECS\ComponentInterface;

class EntityDeclaration
{
    /** @var list<ComponentInterface> */
    private array $components = [];

    /** @var list<EntityDeclaration> */
    private array $children = [];

    /** @var list<string> */
    private array $tags = [];

    private bool $persistent = false;

    private ?string $prefabSource = null;

    private ?EntityDeclaration $parent = null;

    public function __construct(
        private readonly string $name,
        private readonly SceneBuilder $builder,
    ) {}

    public function with(ComponentInterface $component): self
    {
        $this->components[] = $component;
        return $this;
    }

    public function child(string $name): EntityDeclaration
    {
        $child = new EntityDeclaration($name, $this->builder);
        $child->parent = $this;
        $this->children[] = $child;
        return $child;
    }

    public function end(): EntityDeclaration
    {
        if ($this->parent === null) {
            return $this;
        }
        return $this->parent;
    }

    public function tag(string ...$tags): self
    {
        array_push($this->tags, ...$tags);
        return $this;
    }

    public function persist(): self
    {
        $this->persistent = true;
        return $this;
    }

    public function entity(string $name): EntityDeclaration
    {
        return $this->builder->entity($name);
    }

    /**
     * @param class-string<\PHPolygon\Scene\PrefabInterface> $prefabClass
     */
    public function prefab(string $prefabClass, string $name): EntityDeclaration
    {
        return $this->builder->prefab($prefabClass, $name);
    }

    // --- Internal API ---

    public function setPrefabSource(string $className): void
    {
        $this->prefabSource = $className;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return list<ComponentInterface> */
    public function getComponents(): array
    {
        return $this->components;
    }

    /** @return list<EntityDeclaration> */
    public function getChildren(): array
    {
        return $this->children;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    public function getPrefabSource(): ?string
    {
        return $this->prefabSource;
    }
}

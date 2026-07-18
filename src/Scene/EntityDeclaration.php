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

    /**
     * When this declaration was produced by a prefab's build() as a side entity
     * (not the returned anchor), it is flagged here so the transpiler serializes
     * only the prefab reference on the anchor and skips these — the prefab's
     * build() regenerates them on load. Runtime materialize() ignores the flag
     * (the parts are real entities that must exist at runtime).
     */
    private bool $generatedByPrefab = false;

    /**
     * The authored INPUT a prefab anchor was built from (placement Transform3D +
     * the components fed via {@see Prefab::withAuthored()}), recorded so the
     * transpiler serializes the input rather than build()'s derived output. null
     * for non-prefab declarations.
     *
     * @var list<ComponentInterface>|null
     */
    private ?array $prefabAuthored = null;

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

    /**
     * Replace an already-attached component of the same concrete class, or
     * append it if none is present. Used to apply authored per-instance
     * overrides on top of the components a prefab's build() produced, so the
     * authored value wins without duplicating the component.
     */
    public function withOverride(ComponentInterface $component): self
    {
        foreach ($this->components as $i => $existing) {
            if ($existing::class === $component::class) {
                $this->components[$i] = $component;
                return $this;
            }
        }

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

    public function markGeneratedByPrefab(): void
    {
        $this->generatedByPrefab = true;
    }

    public function isGeneratedByPrefab(): bool
    {
        return $this->generatedByPrefab;
    }

    /**
     * @param list<ComponentInterface> $components
     */
    public function setPrefabAuthored(array $components): void
    {
        $this->prefabAuthored = $components;
    }

    /**
     * @return list<ComponentInterface>|null
     */
    public function getPrefabAuthored(): ?array
    {
        return $this->prefabAuthored;
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

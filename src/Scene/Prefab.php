<?php

declare(strict_types=1);

namespace PHPolygon\Scene;

use LogicException;
use PHPolygon\ECS\ComponentInterface;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * Base class for instance-style prefabs that support fluent modifier chains
 * (e.g. `(new Car())->suv()->cabrio()->place(new Vec3(...))`).
 *
 * Use this when a prefab has variants or per-instance configuration. For
 * stateless prefabs that are looked up by name through PrefabRegistry, use
 * AbstractPrefab instead.
 *
 * Lifecycle:
 *   1. Game instantiates the prefab and applies modifiers.
 *   2. Game passes the instance to SceneBuilder::spawn(), which binds the
 *      builder onto the prefab and returns the same instance for chaining.
 *   3. Game calls place() (optionally with a position) to invoke build()
 *      against the bound builder, producing an EntityDeclaration.
 *
 * Subclasses must implement build() and may override getName().
 */
abstract class Prefab implements PrefabInterface
{
    protected ?Vec3 $position = null;
    protected ?Quaternion $rotation = null;
    protected ?Vec3 $scale = null;
    protected ?string $instanceName = null;

    /** @var list<ComponentInterface> */
    private array $authored = [];

    private ?SceneBuilder $boundBuilder = null;

    public static function getName(): string
    {
        $parts = explode('\\', static::class);
        return end($parts);
    }

    final public function at(Vec3 $position): static
    {
        $this->position = $position;
        return $this;
    }

    final public function rotated(Quaternion $rotation): static
    {
        $this->rotation = $rotation;
        return $this;
    }

    final public function scaled(Vec3 $scale): static
    {
        $this->scale = $scale;
        return $this;
    }

    final public function named(string $name): static
    {
        $this->instanceName = $name;
        return $this;
    }

    /**
     * Supply authored components that build() may read as INPUT before it runs
     * (e.g. a design-variant component that selects which geometry/material the
     * prefab assembles). This is the seam that keeps geometry in PHP: the editor
     * / JSON loader stores only the authored component, and build() turns it into
     * geometry — the geometry itself is never serialized.
     */
    final public function withAuthored(ComponentInterface ...$components): static
    {
        array_push($this->authored, ...$components);
        return $this;
    }

    /** @return list<ComponentInterface> */
    final public function getAuthored(): array
    {
        return $this->authored;
    }

    /**
     * The first authored component that is an instance of $class, or null.
     *
     * @template T of ComponentInterface
     * @param class-string<T> $class
     * @return T|null
     */
    final public function authoredComponent(string $class): ?ComponentInterface
    {
        foreach ($this->authored as $component) {
            if ($component instanceof $class) {
                return $component;
            }
        }

        return null;
    }

    final public function getPosition(): Vec3
    {
        return $this->position ?? Vec3::zero();
    }

    final public function getRotation(): Quaternion
    {
        return $this->rotation ?? Quaternion::identity();
    }

    final public function getScale(): Vec3
    {
        return $this->scale ?? Vec3::one();
    }

    final public function getInstanceName(): string
    {
        return $this->instanceName ?? static::getName();
    }

    /** @internal Called by SceneBuilder::spawn(). */
    final public function bindBuilder(SceneBuilder $builder): void
    {
        $this->boundBuilder = $builder;
    }

    final public function place(?Vec3 $position = null): EntityDeclaration
    {
        if ($this->boundBuilder === null) {
            throw new LogicException(
                'Prefab is not bound to a SceneBuilder. Pass it to SceneBuilder::spawn() before calling place().'
            );
        }
        if ($position !== null) {
            $this->position = $position;
        }
        return $this->build($this->boundBuilder);
    }

    abstract public function build(SceneBuilder $builder): EntityDeclaration;
}

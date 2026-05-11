<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Scene;

use LogicException;
use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\EntityDeclaration;
use PHPolygon\Scene\Prefab;
use PHPolygon\Scene\SceneBuilder;

final class PrefabTest extends TestCase
{
    public function testSpawnReturnsSameInstanceForChaining(): void
    {
        $builder = new SceneBuilder();
        $prefab  = new TestModifierPrefab();

        $bound = $builder->spawn($prefab);

        $this->assertSame($prefab, $bound);
    }

    public function testModifierChainPreservesConcreteType(): void
    {
        $builder = new SceneBuilder();

        // Concrete subclass methods must remain reachable after generic
        // modifiers (at/named) thanks to `static` return types.
        $built = $builder
            ->spawn(new TestModifierPrefab())
            ->at(new Vec3(1.0, 2.0, 3.0))
            ->markRed()
            ->named('Hero')
            ->place();

        $this->assertSame('Hero', $built->getName());
        $this->assertCount(1, $built->getComponents());
        $transform = $built->getComponents()[0];
        $this->assertInstanceOf(Transform3D::class, $transform);
        $this->assertEqualsWithDelta(1.0, $transform->position->x, 1e-6);
        $this->assertEqualsWithDelta(2.0, $transform->position->y, 1e-6);
        $this->assertEqualsWithDelta(3.0, $transform->position->z, 1e-6);
    }

    public function testPlaceWithoutBindingThrows(): void
    {
        $prefab = new TestModifierPrefab();

        $this->expectException(LogicException::class);
        $prefab->place();
    }

    public function testPlaceCanReceivePositionDirectly(): void
    {
        $builder = new SceneBuilder();

        $built = $builder
            ->spawn(new TestModifierPrefab())
            ->place(new Vec3(5.0, 0.0, -2.0));

        $transform = $built->getComponents()[0];
        $this->assertInstanceOf(Transform3D::class, $transform);
        $this->assertEqualsWithDelta(5.0, $transform->position->x, 1e-6);
        $this->assertEqualsWithDelta(-2.0, $transform->position->z, 1e-6);
    }

    public function testRotationModifierApplied(): void
    {
        $builder = new SceneBuilder();
        $rot = Quaternion::fromAxisAngle(new Vec3(0, 1, 0), M_PI_2);

        $built = $builder
            ->spawn(new TestModifierPrefab())
            ->rotated($rot)
            ->place();

        $transform = $built->getComponents()[0];
        $this->assertInstanceOf(Transform3D::class, $transform);
        $this->assertTrue($transform->rotation->equals($rot));
    }

    public function testDefaultNameFromClassBasename(): void
    {
        $this->assertSame('TestModifierPrefab', TestModifierPrefab::getName());
    }

    public function testTransform3DHierarchyMaterialises(): void
    {
        $builder = new SceneBuilder();
        $builder->entity('Parent3D')
            ->with(new Transform3D(position: new Vec3(0, 0, 0)))
            ->child('Child3D')
                ->with(new Transform3D(position: new Vec3(1, 0, 0)))
                ->with(new MeshRenderer('mesh.test', 'mat.test'));

        $world = new World();
        $map   = $builder->materialize($world);

        $parentId = $map['Parent3D'];
        $childId  = $map['Child3D'];

        $childTransform = $world->getComponent($childId, Transform3D::class);
        $this->assertInstanceOf(Transform3D::class, $childTransform);
        $this->assertSame($parentId, $childTransform->parentEntityId);

        $parentTransform = $world->getComponent($parentId, Transform3D::class);
        $this->assertInstanceOf(Transform3D::class, $parentTransform);
        $this->assertContains($childId, $parentTransform->childEntityIds);
    }
}

/**
 * Minimal Prefab subclass used to verify the modifier-chain contract.
 *
 * @internal
 */
final class TestModifierPrefab extends Prefab
{
    public bool $isRed = false;

    public function markRed(): static
    {
        $this->isRed = true;
        return $this;
    }

    public function build(SceneBuilder $builder): EntityDeclaration
    {
        return $builder->entity($this->getInstanceName())
            ->with(new Transform3D(
                position: $this->getPosition(),
                rotation: $this->getRotation(),
                scale:    $this->getScale(),
            ));
    }
}

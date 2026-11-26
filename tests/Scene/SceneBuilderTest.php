<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Scene;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Camera2DComponent;
use PHPolygon\Component\NameTag;
use PHPolygon\Component\SpriteRenderer;
use PHPolygon\Component\Transform2D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec2;
use PHPolygon\Scene\EntityDeclaration;
use PHPolygon\Scene\SceneBuilder;

class SceneBuilderTest extends TestCase
{
    public function testEntityDeclaration(): void
    {
        $builder = new SceneBuilder();
        $decl = $builder->entity('Player');

        $this->assertInstanceOf(EntityDeclaration::class, $decl);
        $this->assertCount(1, $builder->getDeclarations());
        $this->assertSame('Player', $decl->getName());
    }

    public function testFluentComponentAttach(): void
    {
        $builder = new SceneBuilder();
        $builder->entity('Camera')
            ->with(new Transform2D())
            ->with(new Camera2DComponent());

        $decl = $builder->getDeclarations()[0];
        $this->assertCount(2, $decl->getComponents());
        $this->assertInstanceOf(Transform2D::class, $decl->getComponents()[0]);
        $this->assertInstanceOf(Camera2DComponent::class, $decl->getComponents()[1]);
    }

    public function testChildHierarchy(): void
    {
        $builder = new SceneBuilder();
        $builder->entity('Player')
            ->with(new Transform2D())
            ->child('Weapon')
                ->with(new Transform2D(position: new Vec2(20, 0)))
                ->with(new SpriteRenderer(textureId: 'sword'));

        $decl = $builder->getDeclarations()[0];
        $this->assertCount(1, $decl->getChildren());

        $child = $decl->getChildren()[0];
        $this->assertSame('Weapon', $child->getName());
        $this->assertCount(2, $child->getComponents());
    }

    public function testPersistentEntity(): void
    {
        $builder = new SceneBuilder();
        $builder->entity('AudioManager')
            ->with(new Transform2D())
            ->persist();

        $decl = $builder->getDeclarations()[0];
        $this->assertTrue($decl->isPersistent());
    }

    public function testTags(): void
    {
        $builder = new SceneBuilder();
        $builder->entity('Enemy')
            ->with(new Transform2D())
            ->tag('hostile', 'npc');

        $decl = $builder->getDeclarations()[0];
        $this->assertSame(['hostile', 'npc'], $decl->getTags());
    }

    public function testMaterializeCreatesEntities(): void
    {
        $builder = new SceneBuilder();
        $builder->entity('Camera')
            ->with(new Transform2D())
            ->with(new Camera2DComponent());

        $builder->entity('Player')
            ->with(new Transform2D(position: new Vec2(100, 200)));

        $world = new World();
        $map = $builder->materialize($world);

        $this->assertCount(2, $map);
        $this->assertArrayHasKey('Camera', $map);
        $this->assertArrayHasKey('Player', $map);
        $this->assertEquals(2, $world->entityCount());

        // Check components were attached
        $cameraId = $map['Camera'];
        $this->assertTrue($world->hasComponent($cameraId, Transform2D::class));
        $this->assertTrue($world->hasComponent($cameraId, Camera2DComponent::class));

        // Auto-attached NameTag
        $this->assertTrue($world->hasComponent($cameraId, NameTag::class));
        $nameTag = $world->getComponent($cameraId, NameTag::class);
        $this->assertSame('Camera', $nameTag->name);
    }

    public function testMaterializeWithHierarchy(): void
    {
        $builder = new SceneBuilder();
        $builder->entity('Parent')
            ->with(new Transform2D())
            ->child('Child')
                ->with(new Transform2D(position: new Vec2(10, 0)));

        $world = new World();
        $map = $builder->materialize($world);

        $parentId = $map['Parent'];
        $childId = $map['Child'];

        // Child should have parentEntityId set
        $childTransform = $world->getComponent($childId, Transform2D::class);
        $this->assertSame($parentId, $childTransform->parentEntityId);

        // Parent should have child in childEntityIds
        $parentTransform = $world->getComponent($parentId, Transform2D::class);
        $this->assertContains($childId, $parentTransform->childEntityIds);
    }

    public function testMaterializeComponentsAreCloned(): void
    {
        $transform = new Transform2D(position: new Vec2(5, 5));
        $builder = new SceneBuilder();
        $builder->entity('A')->with($transform);

        $world = new World();
        $map = $builder->materialize($world);

        $attached = $world->getComponent($map['A'], Transform2D::class);
        $this->assertNotSame($transform, $attached);
        $this->assertEquals(5.0, $attached->position->x);
    }

    public function testChainFromChildBackToBuilder(): void
    {
        $builder = new SceneBuilder();
        $builder->entity('A')
            ->with(new Transform2D())
            ->child('A1')
                ->with(new Transform2D())
            ->entity('B')
                ->with(new Transform2D());

        $this->assertCount(2, $builder->getDeclarations());
        $this->assertSame('A', $builder->getDeclarations()[0]->getName());
        $this->assertSame('B', $builder->getDeclarations()[1]->getName());
    }

    public function testMaterializeAndReleaseMatchesMaterializeAndEmptiesTheBuilder(): void
    {
        $make = static function (): SceneBuilder {
            $builder = new SceneBuilder();
            $builder->entity('Keep')->with(new Transform2D(position: new Vec2(1, 2)))->persist()
                ->child('Kid')->with(new Transform2D(position: new Vec2(3, 4)));
            $builder->entity('Other')->with(new Transform2D());
            $builder->entity('Keep')->with(new Transform2D());   // duplicate name: the later one wins in the map

            return $builder;
        };

        $reference = new World();
        $expectedMap = $make()->materialize($reference);

        $world = new World();
        $builder = $make();
        $persistent = [];
        $map = $builder->materializeAndRelease($world, $persistent);

        $this->assertSame($expectedMap, $map);
        $this->assertSame(['Keep' => true], $persistent);
        $this->assertSame([], $builder->getDeclarations());
        $this->assertSame(
            $reference->getComponent($map['Kid'], Transform2D::class)->position->x,
            $world->getComponent($map['Kid'], Transform2D::class)->position->x,
        );
        $this->assertSame(
            $reference->getComponent($map['Kid'], Transform2D::class)->parentEntityId,
            $world->getComponent($map['Kid'], Transform2D::class)->parentEntityId,
        );
    }

    public function testReleaseBreaksTheDeclarationCycles(): void
    {
        $builder = new SceneBuilder();
        $decl = $builder->entity('A')->with(new Transform2D());
        $child = $decl->child('A1')->with(new Transform2D());
        $ref = \WeakReference::create($child);

        $builder->release();
        unset($decl, $child, $builder);

        // No gc_collect_cycles(): reference counting alone frees the tree.
        $this->assertNull($ref->get());
    }
}
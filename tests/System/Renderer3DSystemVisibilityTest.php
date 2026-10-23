<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Renderer3DSystem;

/**
 * The MeshRenderer::$visible flag (added for the invuln blink) must make the
 * renderer skip the mesh entirely.
 */
class Renderer3DSystemVisibilityTest extends TestCase
{
    public function testInvisibleMeshEmitsNoDrawMesh(): void
    {
        $world = new World();
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 0.0)))
            ->attach(new MeshRenderer(meshId: 'shown', materialId: 'm', visible: true));
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(2.0, 0.0, 0.0)))
            ->attach(new MeshRenderer(meshId: 'hidden', materialId: 'm', visible: false));

        $spy = new class extends NullRenderer3D {
            /** @var list<DrawMesh> */
            public array $draws = [];
            public function render(RenderCommandList $commands): void
            {
                $this->draws = $commands->ofType(DrawMesh::class);
            }
        };

        (new Renderer3DSystem($spy, new RenderCommandList()))->render($world);

        $this->assertCount(1, $spy->draws, 'only the visible mesh is drawn');
        $this->assertSame('shown', $spy->draws[0]->meshId);
    }

    /**
     * The renderer reads the component pools directly: draws keep the order of
     * query(MeshRenderer, Transform3D), a renderer without a Transform3D and a
     * dormant entity draw nothing - whichever pool is the smaller one.
     */
    public function testDrawsFollowTheQueryOrderAndSkipIncompleteAndDormantEntities(): void
    {
        foreach ([false, true] as $moreTransforms) {
            $world = new World();
            $ids = [];
            foreach (['a', 'b', 'c', 'd'] as $i => $name) {
                $ids[$name] = $world->createEntity()
                    ->attach(new Transform3D(new Vec3((float) $i, 0.0, 0.0)))
                    ->attach(new MeshRenderer(meshId: $name, materialId: 'm'))
                    ->id;
            }
            $world->createEntity()->attach(new MeshRenderer(meshId: 'no-transform', materialId: 'm'));
            if ($moreTransforms) {
                for ($i = 0; $i < 3; $i++) {
                    $world->createEntity()->attach(new Transform3D(new Vec3(0.0, (float) $i, 0.0)));
                }
            }
            $world->setEntityDormant($ids['c'], true);

            $spy = new class extends NullRenderer3D {
                /** @var list<DrawMesh> */
                public array $draws = [];
                public function render(RenderCommandList $commands): void
                {
                    $this->draws = $commands->ofType(DrawMesh::class);
                }
            };
            (new Renderer3DSystem($spy, new RenderCommandList()))->render($world);

            $expected = [];
            foreach ($world->query(MeshRenderer::class, Transform3D::class) as $entity) {
                $expected[] = $entity->get(MeshRenderer::class)->meshId;
            }
            $this->assertSame(['a', 'b', 'd'], $expected);
            $this->assertSame($expected, array_map(static fn (DrawMesh $d): string => $d->meshId, $spy->draws));
        }
    }
}

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
}

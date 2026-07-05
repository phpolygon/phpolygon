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
 * The MeshRenderer::$excludeFromGbuffer opt-out (dynamic objects that move
 * outside the baked SDF/probe data and would otherwise pick up voxel mottle)
 * must survive into the emitted DrawMesh command so the deferred G-buffer
 * prepass can skip it. Regression guard: this propagation was silently dropped
 * once, which broke every downstream caller that passed the named argument.
 */
class Renderer3DSystemGbufferExclusionTest extends TestCase
{
    public function testExcludeFromGbufferFlagPropagatesToDrawMesh(): void
    {
        $world = new World();
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 0.0)))
            ->attach(new MeshRenderer(meshId: 'baked', materialId: 'm'));
        $world->createEntity()
            ->attach(new Transform3D(new Vec3(2.0, 0.0, 0.0)))
            ->attach(new MeshRenderer(meshId: 'mover', materialId: 'm', excludeFromGbuffer: true));

        $spy = new class extends NullRenderer3D {
            /** @var list<DrawMesh> */
            public array $draws = [];
            public function render(RenderCommandList $commands): void
            {
                $this->draws = $commands->ofType(DrawMesh::class);
            }
        };

        (new Renderer3DSystem($spy, new RenderCommandList()))->render($world);

        $byMesh = [];
        foreach ($spy->draws as $draw) {
            $byMesh[$draw->meshId] = $draw;
        }

        $this->assertArrayHasKey('baked', $byMesh);
        $this->assertArrayHasKey('mover', $byMesh);
        $this->assertFalse($byMesh['baked']->excludeFromGbuffer, 'default draws stay in the G-buffer prepass');
        $this->assertTrue($byMesh['mover']->excludeFromGbuffer, 'opted-out dynamic draws are flagged for prepass skip');
    }
}

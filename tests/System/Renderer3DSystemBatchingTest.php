<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\Renderer3DSystem;

/**
 * MeshRenderer::$batchable: what survives culling of one mesh + material becomes
 * one instanced draw; everything else about the draw stays as it was.
 */
final class Renderer3DSystemBatchingTest extends TestCase
{
    private NullRenderer3D $spy;

    private RenderCommandList $list;

    private Renderer3DSystem $system;

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('a', new Material());
        MaterialRegistry::register('b', new Material());
        MaterialRegistry::register('glass', new Material(alpha: 0.5));
        $this->spy = new class extends NullRenderer3D {
            /** @var list<object> */
            public array $commands = [];

            public function render(RenderCommandList $commandList): void
            {
                $this->commands = $commandList->getCommands();
            }
        };
        $this->list = new RenderCommandList();
        $this->system = new Renderer3DSystem($this->spy, $this->list);
    }

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    private static function add(World $world, Vec3 $position, string $material, bool $batchable = true, bool $batchTransparent = false): Transform3D
    {
        $entity = $world->createEntity();
        $transform = new Transform3D(position: $position);
        $transform->worldMatrix = Mat4::translation($position->x, $position->y, $position->z);
        $world->attachComponent($entity->id, $transform);
        $world->attachComponent($entity->id, new MeshRenderer('box', $material, castShadows: false, batchable: $batchable, batchTransparent: $batchTransparent));

        return $transform;
    }

    /** @return array{0: list<DrawMesh>, 1: list<DrawMeshInstanced>} */
    private function frame(World $world): array
    {
        $this->list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
        ));
        $this->system->render($world);
        $single = [];
        $instanced = [];
        foreach ($this->spy->commands as $command) {
            if ($command instanceof DrawMesh) {
                $single[] = $command;
            } elseif ($command instanceof DrawMeshInstanced) {
                $instanced[] = $command;
            }
        }

        return [$single, $instanced];
    }

    public function testEqualDrawsMergeAndCastShadowsTravels(): void
    {
        $world = new World();
        self::add($world, new Vec3(-1.0, 0.0, -10.0), 'a');
        self::add($world, new Vec3(1.0, 0.0, -10.0), 'a');
        self::add($world, new Vec3(0.0, 1.0, -10.0), 'b');

        [$single, $instanced] = $this->frame($world);

        self::assertCount(1, $instanced);
        self::assertSame('a', $instanced[0]->materialId);
        self::assertCount(2, $instanced[0]->matrices);
        self::assertFalse($instanced[0]->castShadows);
        self::assertTrue($instanced[0]->bounds?->isUnbounded(), 'its instances are already culled: the renderer derives and tests nothing');
        self::assertCount(1, $single);
        self::assertSame('b', $single[0]->materialId, 'a batch of one stays a plain draw');
    }

    public function testCullingStillAppliesPerEntity(): void
    {
        $world = new World();
        self::add($world, new Vec3(0.0, 0.0, -10.0), 'a');
        self::add($world, new Vec3(0.0, 0.0, 10.0), 'a');

        [$single, $instanced] = $this->frame($world);

        self::assertSame([], $instanced);
        self::assertCount(1, $single);
    }

    public function testMoverKeepsItsOwnDrawWithItsMotion(): void
    {
        $world = new World();
        self::add($world, new Vec3(-1.0, 0.0, -10.0), 'a');
        $mover = self::add($world, new Vec3(1.0, 0.0, -10.0), 'a');
        $this->frame($world);

        $old = $mover->worldMatrix;
        $mover->worldMatrix = Mat4::translation(2.0, 0.0, -10.0);
        [$single, $instanced] = $this->frame($world);

        self::assertSame([], $instanced);
        self::assertCount(2, $single);
        $moved = array_values(array_filter($single, static fn(DrawMesh $d): bool => $d->prevModelMatrix !== null));
        self::assertCount(1, $moved);
        self::assertSame($old, $moved[0]->prevModelMatrix);
    }

    public function testHiddenTransparentAndUnflaggedStaySingle(): void
    {
        $world = new World();
        self::add($world, new Vec3(-1.0, 0.0, -10.0), 'a', batchable: false);
        self::add($world, new Vec3(1.0, 0.0, -10.0), 'a', batchable: false);
        self::add($world, new Vec3(0.0, 1.0, -10.0), 'glass');
        self::add($world, new Vec3(0.0, -1.0, -10.0), 'glass');

        [$single, $instanced] = $this->frame($world);

        self::assertSame([], $instanced);
        self::assertCount(4, $single);
    }

    public function testTransparentBatchOnlyWhenOptedIn(): void
    {
        $world = new World();
        // Panes at different depths so the per-instance sort has something to order.
        foreach ([-10.0, -30.0, -20.0] as $z) {
            self::add($world, new Vec3(0.0, 0.0, $z), 'glass', batchTransparent: true);
        }
        self::add($world, new Vec3(3.0, 0.0, -10.0), 'glass');

        [$single, $instanced] = $this->frame($world);

        self::assertCount(1, $instanced, 'the three opted-in panes become one instanced draw');
        self::assertCount(3, $instanced[0]->matrices);
        self::assertCount(1, $single, 'the pane that did not opt in stays single');
        // Sorted back to front per instance (farthest first).
        $zs = array_map(static fn(Mat4 $m): float => $m->toArray()[14], $instanced[0]->matrices);
        self::assertSame([-30.0, -20.0, -10.0], $zs);
    }
}

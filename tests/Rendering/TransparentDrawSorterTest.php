<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\ClearDepthBuffer;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\ProcModeRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\TransparentDrawSorter;
use PHPolygon\System\Camera3DSystem;
use PHPolygon\System\Renderer3DSystem;

/**
 * Back-to-front order of the transparent pass: key = view depth of the
 * nearest AABB point, one global stable order over every transparent draw
 * (meshes, particles, instanced batches), per-instance order for small
 * instanced batches, water first, barriers respected.
 */
final class TransparentDrawSorterTest extends TestCase
{
    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        ProcModeRegistry::clear();
        TransparentDrawSorter::clearCache();
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('glass', new Material(albedo: new Color(0.1, 0.1, 0.1), alpha: 0.3));
        MaterialRegistry::register('stone', new Material(albedo: new Color(0.5, 0.5, 0.5)));
    }

    protected function tearDown(): void
    {
        ProcModeRegistry::clear();
        TransparentDrawSorter::clearCache();
    }

    private static function camera(): SetCamera
    {
        // Eye at the origin looking down -Z.
        return new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
        );
    }

    private static function box(float $x, float $y, float $z, float $sx, float $sy, float $sz): Mat4
    {
        return Mat4::translation($x, $y, $z)->multiply(Mat4::scaling($sx, $sy, $sz));
    }

    /** @return list<string> mesh ids of the draws in list order */
    private static function order(RenderCommandList $list): array
    {
        $ids = [];
        foreach ($list->getCommands() as $cmd) {
            if ($cmd instanceof DrawMesh || $cmd instanceof DrawMeshInstanced) {
                $ids[] = $cmd->meshId . ':' . $cmd->materialId;
            }
        }
        return $ids;
    }

    public function testLongFacadeInFrontOfDoorLeafIsDrawnAfterTheLeaf(): void
    {
        MeshRegistry::register('facade', BoxMesh::generate(1.0, 1.0, 1.0));
        MeshRegistry::register('leaf', BoxMesh::generate(1.0, 1.0, 1.0));
        // An 8 m facade at z = -5 whose centre sits 3.5 m to the side, and a
        // door leaf 0.5 m BEHIND it straight ahead. The facade's centre is
        // farther from the camera than the leaf's, so a centre sort draws the
        // facade first and the leaf over it. The near face says otherwise.
        $list = new RenderCommandList();
        $list->add(self::camera());
        $list->add(new DrawMesh('facade', 'glass', self::box(3.5, 0.0, -5.0, 8.0, 2.5, 0.03)));
        $list->add(new DrawMesh('leaf', 'glass', self::box(0.0, 0.0, -5.5, 1.0, 2.2, 0.03)));

        TransparentDrawSorter::sortList($list);

        self::assertSame(['leaf:glass', 'facade:glass'], self::order($list), 'the leaf behind the facade comes first');
    }

    public function testOnlyTransparentDrawsMoveAndOtherCommandsKeepTheirSlots(): void
    {
        $list = new RenderCommandList();
        $cam = self::camera();
        $list->add($cam);
        $near = new DrawMesh('box', 'glass', Mat4::translation(0.0, 0.0, -2.0));
        $opaque = new DrawMesh('box', 'stone', Mat4::translation(0.0, 0.0, -3.0));
        $far = new DrawMesh('box', 'glass', Mat4::translation(0.0, 0.0, -9.0));
        $list->add($near);
        $list->add($opaque);
        $list->add($far);

        TransparentDrawSorter::sortList($list);

        self::assertSame([$cam, $far, $opaque, $near], $list->getCommands());
    }

    public function testEqualDepthsKeepSubmissionOrder(): void
    {
        $list = new RenderCommandList();
        $list->add(self::camera());
        $a = new DrawMesh('box', 'glass', Mat4::translation(-1.0, 0.0, -4.0));
        $b = new DrawMesh('box', 'glass', Mat4::translation(1.0, 0.0, -4.0));
        $list->add($a);
        $list->add($b);

        TransparentDrawSorter::sortList($list);

        self::assertSame($a, $list->getCommands()[1]);
        self::assertSame($b, $list->getCommands()[2]);
    }

    public function testParticlesAndInstancedBatchesJoinTheGlobalOrder(): void
    {
        $list = new RenderCommandList();
        $list->add(self::camera());
        $pane = new DrawMesh('box', 'glass', self::box(0.0, 0.0, -5.0, 2.0, 2.0, 0.03));
        $list->add($pane);
        // Bubbles BEHIND the pane, appended after it by another system.
        $flat = [];
        foreach ([-8.0, -9.0] as $z) {
            array_push($flat, 0.2, 0.0, 0.0, 0.0, 0.0, 0.2, 0.0, 0.0, 0.0, 0.0, 0.2, 0.0, 0.0, 0.0, $z, 1.0);
        }
        $bubbles = DrawMeshInstanced::flat('box', 'glass', $flat, 2);
        $list->add($bubbles);

        TransparentDrawSorter::sortList($list);

        $cmds = $list->getCommands();
        self::assertInstanceOf(DrawMeshInstanced::class, $cmds[1], 'the bubbles behind the pane are drawn first');
        self::assertSame($pane, $cmds[2]);
    }

    public function testSmallInstancedBatchIsSortedPerInstance(): void
    {
        $list = new RenderCommandList();
        $list->add(self::camera());
        // Three panes as one batch, front to back (the wrong order).
        $list->add(new DrawMeshInstanced('box', 'glass', [
            self::box(0.0, 0.0, -3.0, 2.0, 2.0, 0.03),
            self::box(0.2, 0.0, -5.0, 2.0, 2.0, 0.03),
            self::box(0.4, 0.0, -7.0, 2.0, 2.0, 0.03),
        ]));

        TransparentDrawSorter::sortList($list);

        $batch = $list->getCommands()[1];
        self::assertInstanceOf(DrawMeshInstanced::class, $batch);
        $zs = array_map(static fn (Mat4 $m): float => $m->getTranslation()->z, $batch->matrices);
        self::assertSame([-7.0, -5.0, -3.0], $zs, 'instances are drawn back to front');
        self::assertFalse($batch->isStatic, 'a camera-ordered batch is not cached as static');
    }

    public function testSmallFlatBatchIsSortedPerInstance(): void
    {
        $flat = [];
        foreach ([-2.0, -6.0, -4.0] as $z) {
            array_push($flat, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, $z, 1.0);
        }
        $list = new RenderCommandList();
        $list->add(self::camera());
        $list->add(DrawMeshInstanced::flat('box', 'glass', $flat, 3));

        TransparentDrawSorter::sortList($list);

        $batch = $list->getCommands()[1];
        self::assertInstanceOf(DrawMeshInstanced::class, $batch);
        $f = $batch->flatMatrices;
        self::assertSame([-6.0, -4.0, -2.0], [$f[14], $f[30], $f[46]]);
        self::assertSame(3, $batch->instanceCount);
    }

    public function testLargeBatchKeepsItsInstanceOrder(): void
    {
        $matrices = [];
        for ($i = 0; $i <= TransparentDrawSorter::PER_INSTANCE_SORT_MAX; $i++) {
            $matrices[] = Mat4::translation(0.0, 0.0, -1.0 - $i * 0.1);
        }
        $cmd = new DrawMeshInstanced('box', 'glass', $matrices);
        $list = new RenderCommandList();
        $list->add(self::camera());
        $list->add($cmd);

        TransparentDrawSorter::sortList($list);

        self::assertSame($cmd, $list->getCommands()[1]);
    }

    public function testWaterSurfaceIsDrawnBeforeEveryOtherTransparent(): void
    {
        ProcModeRegistry::map('sea_', 2);
        MaterialRegistry::register('sea_surface', new Material(albedo: new Color(0.0, 0.2, 0.3), alpha: 0.8));
        $list = new RenderCommandList();
        $list->add(self::camera());
        $pane = new DrawMesh('box', 'glass', self::box(0.0, 0.0, -6.0, 2.0, 2.0, 0.03));
        // A sea plane reaching under the camera: its nearest point is closer
        // than the pane, yet it must be drawn first.
        $sea = new DrawMesh('box', 'sea_surface', self::box(0.0, -1.0, -200.0, 400.0, 0.01, 400.0));
        $list->add($pane);
        $list->add($sea);

        TransparentDrawSorter::sortList($list);

        self::assertSame($sea, $list->getCommands()[1]);
        self::assertSame($pane, $list->getCommands()[2]);
    }

    public function testDrawsNeverCrossADepthClear(): void
    {
        $list = new RenderCommandList();
        $list->add(self::camera());
        $near = new DrawMesh('box', 'glass', Mat4::translation(0.0, 0.0, -2.0));
        $overlayFar = new DrawMesh('box', 'glass', Mat4::translation(0.0, 0.0, -50.0));
        $list->add($near);
        $list->add(new ClearDepthBuffer());
        $list->add($overlayFar);

        TransparentDrawSorter::sortList($list);

        self::assertSame($near, $list->getCommands()[1]);
        self::assertSame($overlayFar, $list->getCommands()[3]);
    }

    public function testStorageBufferBatchHasNoPositionAndIsDrawnLast(): void
    {
        $list = new RenderCommandList();
        $list->add(self::camera());
        $gpu = DrawMeshInstanced::fromStorageBuffer('box', 'glass', new \stdClass(), 10);
        $pane = new DrawMesh('box', 'glass', Mat4::translation(0.0, 0.0, -3.0));
        $list->add($gpu);
        $list->add($pane);

        TransparentDrawSorter::sortList($list);

        self::assertSame($pane, $list->getCommands()[1]);
        self::assertSame($gpu, $list->getCommands()[2]);
    }

    public function testListWithoutCameraIsLeftAlone(): void
    {
        $list = new RenderCommandList();
        $a = new DrawMesh('box', 'glass', Mat4::translation(0.0, 0.0, -2.0));
        $b = new DrawMesh('box', 'glass', Mat4::translation(0.0, 0.0, -9.0));
        $list->add($a);
        $list->add($b);

        TransparentDrawSorter::sortList($list);

        self::assertSame([$a, $b], $list->getCommands());
    }

    public function testEcsPathSortsByTheRenderedCameraWithEyeOffset(): void
    {
        // The camera ENTITY sits at the origin, turned to look down +Z, with an
        // eye offset of 8 m forward. Both panes are in front of the eye; the
        // order comes from the SetCamera the frame renders with.
        $world = new World();
        $list = new RenderCommandList();
        $renderer = new NullRenderer3D();

        $camera = $world->createEntity();
        $camera->attach(new Camera3DComponent(active: true, eyeOffset: new Vec3(0.0, 0.0, 8.0)));
        $camera->attach(new Transform3D(new Vec3(0.0, 0.0, 0.0), Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), M_PI)));

        // With the eye at z = +8 looking down +Z, the pane at z = +9 is right
        // in front of it (depth 1) and the pane at z = +20 is far (depth 12).
        $world->createEntity()
            ->attach(new MeshRenderer('box', 'glass'))
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 9.0)));
        $world->createEntity()
            ->attach(new MeshRenderer('box', 'glass'))
            ->attach(new Transform3D(new Vec3(0.0, 0.0, 20.0)));

        $world->addSystem(new Camera3DSystem($list, 640, 640));
        $world->addSystem(new Renderer3DSystem($renderer, $list));
        $world->render();

        $last = $renderer->getLastCommandList();
        self::assertNotNull($last);
        $draws = $last->ofType(DrawMesh::class);
        self::assertCount(2, $draws, 'both panes are in front of the eye and survive culling');
        self::assertEqualsWithDelta(20.0, $draws[0]->modelMatrix->getTranslation()->z, 1e-9, 'far pane first');
        self::assertEqualsWithDelta(9.0, $draws[1]->modelMatrix->getTranslation()->z, 1e-9);
    }
}

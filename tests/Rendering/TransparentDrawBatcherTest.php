<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshData;
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
use PHPolygon\Rendering\TransparentDrawBatcher;
use PHPolygon\Rendering\TransparentDrawSorter;
use PHPolygon\System\Camera3DSystem;
use PHPolygon\System\Renderer3DSystem;
use PHPUnit\Framework\TestCase;

/**
 * Runs of identical flat transparent draws become one instanced draw after the
 * sort, in the order the separate draws had; everything that could change the
 * blend result stays a draw of its own.
 */
final class TransparentDrawBatcherTest extends TestCase
{
    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        ProcModeRegistry::clear();
        TransparentDrawSorter::clearCache();
        TransparentDrawBatcher::clearCache();
        // A one-sided 1 x 1 pane facing +Z (counter-clockwise seen from +Z).
        MeshRegistry::register('pane', new MeshData(
            [-0.5, -0.5, 0.0, 0.5, -0.5, 0.0, 0.5, 0.5, 0.0, -0.5, 0.5, 0.0],
            [0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0],
            [0.0, 0.0, 1.0, 0.0, 1.0, 1.0, 0.0, 1.0],
            [0, 1, 2, 0, 2, 3],
        ));
        MeshRegistry::register('pane2', MeshRegistry::get('pane') ?? throw new \LogicException('pane'));
        MeshRegistry::register('box', BoxMesh::generate(1.0, 1.0, 1.0));
        MaterialRegistry::register('glass', new Material(albedo: new Color(0.1, 0.1, 0.1), alpha: 0.3));
        MaterialRegistry::register('tint', new Material(albedo: new Color(0.1, 0.4, 0.1), alpha: 0.5));
        MaterialRegistry::register('stone', new Material(albedo: new Color(0.5, 0.5, 0.5)));
    }

    protected function tearDown(): void
    {
        TransparentDrawBatcher::clearCache();
        TransparentDrawSorter::clearCache();
        ProcModeRegistry::clear();
    }

    private static function at(float $z, float $x = 0.0): Mat4
    {
        return Mat4::translation($x, 0.0, $z);
    }

    private static function camera(): SetCamera
    {
        return new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -1.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 500.0),
        );
    }

    /** @return list<string> */
    private static function shape(RenderCommandList $list): array
    {
        $out = [];
        foreach ($list->getCommands() as $c) {
            $out[] = match (true) {
                $c instanceof DrawMeshInstanced => 'I:' . $c->meshId . ':' . $c->materialId . 'x' . count($c->matrices),
                $c instanceof DrawMesh => 'D:' . $c->meshId . ':' . $c->materialId,
                default => 'C:' . (new \ReflectionClass($c))->getShortName(),
            };
        }

        return $out;
    }

    /** @param list<object> $commands */
    private static function listOf(array $commands): RenderCommandList
    {
        $list = new RenderCommandList();
        foreach ($commands as $c) {
            $list->add($c);
        }

        return $list;
    }

    public function testARunOfPanesBecomesOneBatchInTheOrderOfTheDraws(): void
    {
        $list = self::listOf([
            self::camera(),
            new DrawMesh('pane', 'glass', self::at(-9.0)),
            new DrawMesh('pane', 'glass', self::at(-7.0)),
            new DrawMesh('pane', 'glass', self::at(-5.0)),
        ]);
        $saved = TransparentDrawBatcher::batchList($list);

        self::assertSame(2, $saved);
        self::assertSame(['C:SetCamera', 'I:pane:glassx3'], self::shape($list));
        $batch = $list->getCommands()[1];
        self::assertInstanceOf(DrawMeshInstanced::class, $batch);
        self::assertFalse($batch->isStatic, 'its order follows the camera');
        self::assertSame([-9.0, -7.0, -5.0], array_map(static fn (Mat4 $m): float => $m->getTranslation()->z, $batch->matrices));
    }

    public function testAnotherTransparentDrawBetweenTwoPanesKeepsThemApart(): void
    {
        $list = self::listOf([
            self::camera(),
            new DrawMesh('pane', 'glass', self::at(-9.0)),
            new DrawMesh('pane', 'tint', self::at(-8.0)),
            new DrawMesh('pane', 'glass', self::at(-7.0)),
            new DrawMesh('pane', 'glass', self::at(-6.0)),
        ]);
        self::assertSame(1, TransparentDrawBatcher::batchList($list));
        self::assertSame(['C:SetCamera', 'D:pane:glass', 'D:pane:tint', 'I:pane:glassx2'], self::shape($list));
    }

    public function testAnOpaqueDrawBetweenPanesDoesNotBreakTheRunAndStaysInPlace(): void
    {
        $stone = new DrawMesh('box', 'stone', self::at(-3.0));
        $list = self::listOf([
            self::camera(),
            new DrawMesh('pane', 'glass', self::at(-9.0)),
            $stone,
            new DrawMesh('pane', 'glass', self::at(-7.0)),
        ]);
        self::assertSame(1, TransparentDrawBatcher::batchList($list));
        self::assertSame(['C:SetCamera', 'I:pane:glassx2', 'D:box:stone'], self::shape($list));
        self::assertSame($stone, $list->getCommands()[2]);
    }

    public function testACommandThatIsNotADrawEndsTheRun(): void
    {
        $list = self::listOf([
            self::camera(),
            new DrawMesh('pane', 'glass', self::at(-9.0)),
            new ClearDepthBuffer(),
            new DrawMesh('pane', 'glass', self::at(-7.0)),
        ]);
        self::assertSame(0, TransparentDrawBatcher::batchList($list));
        self::assertSame(['C:SetCamera', 'D:pane:glass', 'C:ClearDepthBuffer', 'D:pane:glass'], self::shape($list));
    }

    public function testAClosedMeshKeepsItsOwnDraws(): void
    {
        $list = self::listOf([
            self::camera(),
            new DrawMesh('box', 'glass', self::at(-9.0)),
            new DrawMesh('box', 'glass', self::at(-7.0)),
        ]);
        self::assertSame(0, TransparentDrawBatcher::batchList($list), 'far faces then near faces per box cannot be one instanced draw');
    }

    public function testDrawsThatCarryMoreThanAMatrixStayAlone(): void
    {
        $prev = self::at(-8.0, 0.5);
        $list = self::listOf([
            self::camera(),
            new DrawMesh('pane', 'glass', self::at(-9.0)),
            new DrawMesh('pane', 'glass', self::at(-8.0), prevModelMatrix: $prev),
            new DrawMesh('pane', 'glass', self::at(-7.0), params: [1.0, 2.0, 3.0]),
            new DrawMesh('pane', 'glass', self::at(-6.0), excludeFromGbuffer: true),
            new DrawMesh('pane', 'glass', self::at(-5.0), shadowOnly: true),
            new DrawMesh('pane', 'glass', self::at(-4.0), castShadows: false),
            new DrawMesh('pane2', 'glass', self::at(-3.0)),
        ]);
        self::assertSame(0, TransparentDrawBatcher::batchList($list));
        self::assertCount(8, $list->getCommands());
    }

    public function testAnInstancedBatchOrOpaqueFirstDrawIsNotTouched(): void
    {
        $inst = new DrawMeshInstanced('pane', 'glass', [self::at(-9.0), self::at(-8.0)]);
        $list = self::listOf([
            self::camera(),
            $inst,
            new DrawMesh('pane', 'glass', self::at(-7.0)),
            new DrawMesh('pane', 'glass', self::at(-6.0)),
        ]);
        self::assertSame(1, TransparentDrawBatcher::batchList($list));
        self::assertSame($inst, $list->getCommands()[1]);
        self::assertSame(['C:SetCamera', 'I:pane:glassx2', 'I:pane:glassx2'], self::shape($list));
    }

    public function testSortThenBatchOnlyMergesWhatTheSortPutNextToEachOther(): void
    {
        // Two panes of a facade with a door leaf of another material between them in depth.
        $list = self::listOf([
            self::camera(),
            new DrawMesh('pane', 'glass', self::at(-4.0)),
            new DrawMesh('pane', 'glass', self::at(-9.0)),
            new DrawMesh('pane', 'tint', self::at(-6.0)),
            new DrawMesh('pane', 'glass', self::at(-12.0)),
            new DrawMesh('pane', 'glass', self::at(-11.0)),
        ]);
        TransparentDrawSorter::sortList($list);
        TransparentDrawBatcher::batchList($list);

        // back to front: -12, -11, -9 (glass run), -6 (tint), -4 (glass)
        self::assertSame(['C:SetCamera', 'I:pane:glassx3', 'D:pane:tint', 'D:pane:glass'], self::shape($list));
        $batch = $list->getCommands()[1];
        self::assertInstanceOf(DrawMeshInstanced::class, $batch);
        self::assertSame([-12.0, -11.0, -9.0], array_map(static fn (Mat4 $m): float => $m->getTranslation()->z, $batch->matrices));
    }

    public function testTheSystemBatchesOnlyWhenAsked(): void
    {
        $run = function (bool $batch): array {
            $world = new World();
            $list = new RenderCommandList();
            $renderer = new NullRenderer3D();
            $camera = $world->createEntity();
            $camera->attach(new Camera3DComponent(active: true));
            $camera->attach(new Transform3D(new Vec3(0.0, 0.0, 0.0), Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), M_PI)));
            foreach ([20.0, 22.0, 24.0, 26.0] as $z) {
                $world->createEntity()->attach(new MeshRenderer('pane', 'glass'))->attach(new Transform3D(new Vec3(0.0, 0.0, $z)));
            }
            $world->addSystem(new Camera3DSystem($list, 640, 640));
            $system = new Renderer3DSystem($renderer, $list);
            $system->batchTransparentRuns = $batch;
            $world->addSystem($system);
            $world->render();
            $last = $renderer->getLastCommandList();
            self::assertNotNull($last);

            return [$last, $system->transparentDrawsSaved];
        };

        [$plain, $savedOff] = $run(false);
        self::assertCount(4, $plain->ofType(DrawMesh::class), 'default: one draw per pane');
        self::assertSame(0, $savedOff);

        [$batched, $saved] = $run(true);
        self::assertCount(0, $batched->ofType(DrawMesh::class));
        $inst = $batched->ofType(DrawMeshInstanced::class);
        self::assertCount(1, $inst);
        self::assertSame(3, $saved);
        self::assertSame([26.0, 24.0, 22.0, 20.0], array_map(static fn (Mat4 $m): float => $m->getTranslation()->z, $inst[0]->matrices), 'far pane first');
    }
}

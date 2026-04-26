<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Billboard;
use PHPolygon\Component\BillboardMode;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\BillboardSystem;

class BillboardSystemTest extends TestCase
{
    private function createSystemWithCamera(Vec3 $cameraPos): array
    {
        $commandList = new RenderCommandList();

        $view = Mat4::lookAt($cameraPos, Vec3::zero(), new Vec3(0.0, 1.0, 0.0));
        $proj = Mat4::perspective(deg2rad(60.0), 1.0, 0.1, 100.0);
        $commandList->add(new SetCamera($view, $proj));

        $system = new BillboardSystem($commandList);
        return [$system, $commandList];
    }

    public function testBillboardDefaults(): void
    {
        $bb = new Billboard();
        $this->assertSame(BillboardMode::Full, $bb->mode);
    }

    public function testEmitsDrawMeshForBillboard(): void
    {
        [$system, $commandList] = $this->createSystemWithCamera(new Vec3(0.0, 5.0, 10.0));
        $world = new World();

        $entity = $world->createEntity();
        $entity->attach(new Transform3D(position: new Vec3(0.0, 0.0, 0.0)));
        $entity->attach(new MeshRenderer('quad', 'sprite_mat'));
        $entity->attach(new Billboard());

        $system->render($world);

        $draws = $commandList->ofType(DrawMesh::class);
        $this->assertCount(1, $draws);
        $this->assertSame('quad', $draws[0]->meshId);
        $this->assertSame('sprite_mat', $draws[0]->materialId);
    }

    public function testNoCameraNoDraws(): void
    {
        $commandList = new RenderCommandList();
        $system = new BillboardSystem($commandList);
        $world = new World();

        $entity = $world->createEntity();
        $entity->attach(new Transform3D());
        $entity->attach(new MeshRenderer('quad', 'mat'));
        $entity->attach(new Billboard());

        $system->render($world);

        $draws = $commandList->ofType(DrawMesh::class);
        $this->assertCount(0, $draws);
    }

    public function testAxisYModePreservesVertical(): void
    {
        [$system, $commandList] = $this->createSystemWithCamera(new Vec3(10.0, 5.0, 0.0));
        $world = new World();

        $entity = $world->createEntity();
        $entity->attach(new Transform3D(position: Vec3::zero()));
        $entity->attach(new MeshRenderer('quad', 'mat'));
        $entity->attach(new Billboard(BillboardMode::AxisY));

        $system->render($world);

        $draws = $commandList->ofType(DrawMesh::class);
        $this->assertCount(1, $draws);

        // The model matrix should have an identity-like Y column (no pitch rotation)
        $modelMatrix = $draws[0]->modelMatrix;
        // Y-axis should remain (0,1,0) since AxisY only rotates around Y
        $this->assertEqualsWithDelta(0.0, $modelMatrix->get(0, 1), 1e-4);
        $this->assertEqualsWithDelta(1.0, $modelMatrix->get(1, 1), 1e-4);
        $this->assertEqualsWithDelta(0.0, $modelMatrix->get(2, 1), 1e-4);
    }

    public function testMultipleBillboards(): void
    {
        [$system, $commandList] = $this->createSystemWithCamera(new Vec3(0.0, 10.0, 10.0));
        $world = new World();

        for ($i = 0; $i < 5; $i++) {
            $entity = $world->createEntity();
            $entity->attach(new Transform3D(position: new Vec3((float)$i * 3.0, 0.0, 0.0)));
            $entity->attach(new MeshRenderer('quad', 'mat'));
            $entity->attach(new Billboard());
        }

        $system->render($world);

        $draws = $commandList->ofType(DrawMesh::class);
        $this->assertCount(5, $draws);
    }
}

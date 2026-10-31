<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\System;

use PHPolygon\Component\GrassField;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\Quality\GrassQuality;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\GrassSystem;
use PHPolygon\Vegetation\GrassDetail;
use PHPolygon\Vegetation\GrassMask;

/**
 * CPU cost per frame of a grass field at medium quality (cells prewarmed):
 * at rest (same camera: the batches are re-sent), turning on the spot (the
 * visible cells are re-tested), walking (a rebuild every 1.5 m) and building
 * cells of tufts (mask with a path, ground callable) within the frame budget:
 * the last one has to stay near {@see GrassDetail::$generateBudgetMs}, however
 * long a cell takes. `benchmarks/grass-walk.php` gives the longest tick of a walk.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/System/GrassSystemBench.php --report=aggregate
 */
final class GrassSystemBench
{
    private World $world;
    private RenderCommandList $list;
    private GrassSystem $system;
    private ?GrassSystem $building = null;
    private int $frame = 0;

    public function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        $mask = (new GrassMask())->excludePath([[-100.0, 5.0], [0.0, 0.0], [100.0, -20.0]], 1.3, 0.8);
        $this->world = new World();
        $this->world->createEntity()->attach(new GrassField(-90.0, -90.0, 90.0, 90.0, static fn(float $x, float $z): float => 0.3 * sin($x * 0.07), $mask));
        $this->list = new RenderCommandList();
        $this->system = new GrassSystem($this->list, GrassQuality::Medium);
        $this->system->prewarm($this->world, 0.0, 0.0, 75.0);
        $this->camera(0.0, 0.0);
        $this->system->render($this->world);
    }

    private function camera(float $z, float $yaw): void
    {
        $this->list->clear();
        $eye = new Vec3(0.0, 1.7, 10.0 + $z);
        $this->list->add(new SetCamera(
            Mat4::lookAt($eye, new Vec3(sin($yaw) * 50.0, 1.0, $eye->z - cos($yaw) * 50.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(70.0), 16 / 9, 0.1, 2000.0),
        ));
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(500)
     * @Iterations(3)
     */
    public function benchAtRest(): void
    {
        $this->camera(0.0, 0.0);
        $this->system->render($this->world);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(100)
     * @Iterations(3)
     */
    public function benchTurningOnTheSpot(): void
    {
        $this->camera(0.0, ($this->frame++ % 40) * 0.002);
        $this->system->render($this->world);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(50)
     * @Iterations(3)
     */
    public function benchWalkingRebuild(): void
    {
        $this->camera(-($this->frame++ % 2) * 2.0, 0.0);
        $this->system->render($this->world);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(200)
     * @Iterations(3)
     */
    public function benchBuildingCellsWithinTheBudget(): void
    {
        // A hard budget and a queue that never runs dry: every rev is a frame that
        // spends its budget. The cells are gone with the system, not the ground.
        $this->building ??= new GrassSystem($this->list, new GrassDetail(generateBurst: 1.0));
        $this->camera(0.0, 0.0);
        $this->building->render($this->world);
        if ($this->building->lastPendingCells === 0) {
            $this->building = null;
        }
    }
}

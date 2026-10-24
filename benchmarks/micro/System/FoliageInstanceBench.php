<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\System;

use PHPolygon\Component\FoliageInstances;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\FoliageInstanceSystem;
use PHPolygon\System\Renderer3DSystem;
use PHPolygon\System\Transform3DSystem;
use PHPolygon\Vegetation\GrowthStage;
use PHPolygon\Vegetation\PlantForm;
use PHPolygon\Vegetation\PlantLibrary;
use PHPolygon\Vegetation\PlantLod;
use PHPolygon\Vegetation\PlantPart;
use PHPolygon\Vegetation\PlantSpec;
use PHPolygon\Vegetation\PlantVariant;

/**
 * CPU cost per frame of 600 trees: as entities (one MeshRenderer per part,
 * collected by Renderer3DSystem into DrawMesh commands - how individual plant
 * entities are drawn) versus one FoliageInstances component through
 * FoliageInstanceSystem, in steady state (camera still: the cached batches
 * are re-sent) and when the camera moved (groups rebuilt).
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/System/FoliageInstanceBench.php --report=aggregate
 */
final class FoliageInstanceBench
{
    private const int TREES = 600;

    private World $entityWorld;
    private World $foliageWorld;
    private RenderCommandList $entityList;
    private RenderCommandList $foliageList;
    private Renderer3DSystem $renderer;
    private FoliageInstanceSystem $foliage;
    private int $frame = 0;

    public function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        PlantLibrary::clear();
        MaterialRegistry::register('bark', Material::bark(Color::srgb('#6B4A32')));
        MaterialRegistry::register('leaf', Material::foliage(Color::srgb('#5E9E3A')));
        $variant = PlantLibrary::register(new PlantVariant('bench_tree', PlantSpec::tree(PlantForm::Round, 9.0, 3.2, 3), 'bark', 'leaf'));

        $this->entityWorld = new World();
        $this->entityList = new RenderCommandList();
        $this->renderer = new Renderer3DSystem(new NullRenderer3D(), $this->entityList);
        $transforms = new Transform3DSystem();
        $this->entityWorld->addSystem($transforms);

        $this->foliageWorld = new World();
        $this->foliageList = new RenderCommandList();
        $this->foliage = new FoliageInstanceSystem($this->foliageList);
        $instances = new FoliageInstances('bench_tree');

        for ($i = 0; $i < self::TREES; $i++) {
            $pos = new Vec3(($i % 30) * 12.0 - 180.0, 0.0, -intdiv($i, 30) * 12.0);
            $yaw = $i * 0.7;
            foreach ([PlantPart::Wood, PlantPart::Leaf] as $part) {
                $this->entityWorld->createEntity()
                    ->attach(new Transform3D($pos, Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), $yaw)))
                    ->attach(new MeshRenderer($variant->meshId(GrowthStage::Full, PlantLod::Lod0, $part), $part === PlantPart::Wood ? 'bark' : 'leaf'));
            }
            $instances->add($pos, $yaw);
        }
        $this->foliageWorld->createEntity()->attach($instances);
        $this->entityWorld->update(0.016);
        $this->camera($this->foliageList, 0.0);
        $this->foliage->render($this->foliageWorld);
    }

    private function camera(RenderCommandList $list, float $z): void
    {
        $list->clear();
        $list->add(new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 1.7, 10.0 + $z), new Vec3(0.0, 1.7, -100.0 + $z), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(70.0), 16 / 9, 0.1, 2000.0),
        ));
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(20)
     * @Iterations(5)
     */
    public function benchTreesAsEntities(): void
    {
        $this->camera($this->entityList, 0.0);
        $this->renderer->render($this->entityWorld);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(200)
     * @Iterations(5)
     */
    public function benchFoliageInstancesSteady(): void
    {
        $this->camera($this->foliageList, 0.0);
        $this->foliage->render($this->foliageWorld);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(50)
     * @Iterations(5)
     */
    public function benchFoliageInstancesCameraMoving(): void
    {
        $this->camera($this->foliageList, ($this->frame++ % 2) * 3.0);
        $this->foliage->render($this->foliageWorld);
    }
}

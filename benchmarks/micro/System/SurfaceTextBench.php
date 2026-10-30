<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\System;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\SurfaceText;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\QuadMesh;
use PHPolygon\Locale\LocaleManager;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\Text\SurfaceTextBaker;
use PHPolygon\System\Renderer3DSystem;
use PHPolygon\System\SurfaceTextSystem;
use PHPolygon\Testing\NullTextureManager;

/**
 * CPU cost per frame of 200 text surfaces (30 distinct texts): the idle
 * SurfaceTextSystem tick, and collecting them in Renderer3DSystem as single
 * draws versus MeshRenderer::$batchable.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/System/SurfaceTextBench.php --report=aggregate
 */
final class SurfaceTextBench
{
    private const int SURFACES = 200;
    private const int TEXTS = 30;

    private World $single;
    private World $batched;
    private RenderCommandList $singleList;
    private RenderCommandList $batchedList;
    private Renderer3DSystem $singleRenderer;
    private Renderer3DSystem $batchedRenderer;
    private SurfaceTextSystem $system;
    private SetCamera $camera;

    public function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
        QuadMesh::unit();
        for ($i = 0; $i < self::TEXTS; $i++) {
            MaterialRegistry::register("text_{$i}", new Material());
        }

        $this->single = self::world(false);
        $this->batched = self::world(true);
        $this->singleList = new RenderCommandList();
        $this->batchedList = new RenderCommandList();
        $this->singleRenderer = new Renderer3DSystem(new NullRenderer3D(), $this->singleList);
        $this->batchedRenderer = new Renderer3DSystem(new NullRenderer3D(), $this->batchedList);
        $this->camera = new SetCamera(
            Mat4::lookAt(new Vec3(0.0, 2.0, 40.0), new Vec3(0.0, 2.0, 0.0), new Vec3(0.0, 1.0, 0.0)),
            Mat4::perspective(deg2rad(70.0), 1.777, 0.1, 500.0),
        );

        $locale = new LocaleManager('en', 'en');
        $this->system = new SurfaceTextSystem($locale, new SurfaceTextBaker($locale, new NullTextureManager()));
        $this->system->prewarm($this->batched);
    }

    private static function world(bool $batchable): World
    {
        $world = new World();
        for ($i = 0; $i < self::SURFACES; $i++) {
            $entity = $world->createEntity();
            $position = new Vec3(($i % 20) * 3.0 - 30.0, ($i % 5) * 1.0, -(int) ($i / 20) * 2.0);
            $transform = new Transform3D(position: $position);
            $transform->worldMatrix = Mat4::translation($position->x, $position->y, $position->z);
            $world->attachComponent($entity->id, $transform);
            $world->attachComponent($entity->id, new MeshRenderer(QuadMesh::UNIT_ID, 'text_' . ($i % self::TEXTS), castShadows: false, batchable: $batchable));
            $world->attachComponent($entity->id, new SurfaceText(text: 'Text ' . ($i % self::TEXTS)));
        }

        return $world;
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(2000)
     * @Iterations(5)
     */
    public function benchSystemIdleTick(): void
    {
        $this->system->update($this->batched, 0.016);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(200)
     * @Iterations(5)
     */
    public function benchSingleDraws(): void
    {
        $this->singleList->add($this->camera);
        $this->singleRenderer->render($this->single);
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(200)
     * @Iterations(5)
     */
    public function benchBatchedDraws(): void
    {
        $this->batchedList->add($this->camera);
        $this->batchedRenderer->render($this->batched);
    }
}

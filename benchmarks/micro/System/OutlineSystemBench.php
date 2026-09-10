<?php

declare(strict_types=1);

namespace PHPolygon\Benchmarks\Micro\System;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Outline;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\System\OutlineSystem;

/**
 * OutlineSystem runs every render frame. A world has thousands of meshes and at
 * most a handful of outlined objects, so the cost that matters is walking the
 * Outline pool - it must not scale with the size of the world.
 *
 * Run:
 *   vendor/bin/phpbench run benchmarks/micro/System/OutlineSystemBench.php --report=aggregate
 */
final class OutlineSystemBench
{
    private const int MESHES = 2600;
    private const int CHILDREN = 8;

    private World $world;
    private OutlineSystem $system;
    private RenderCommandList $list;

    public function setUp(): void
    {
        $this->world = new World();
        $this->list = new RenderCommandList();
        $this->system = new OutlineSystem($this->list);

        for ($i = 0; $i < self::MESHES; $i++) {
            $this->world->createEntity()
                ->attach(new Transform3D(new Vec3((float) ($i % 50), 0.0, (float) intdiv($i, 50))))
                ->attach(new MeshRenderer('box', 'm'));
        }

        // One outlined object made of a root and a few child parts.
        $root = $this->world->createEntity();
        $rootTransform = new Transform3D();
        $root->attach($rootTransform)->attach(new Outline());
        for ($c = 0; $c < self::CHILDREN; $c++) {
            $child = $this->world->createEntity();
            $childTransform = new Transform3D(new Vec3((float) $c, 1.0, 0.0));
            $child->attach($childTransform)->attach(new MeshRenderer('part', 'm'));
            $rootTransform->addChild($childTransform, $child->id, $root->id);
        }
    }

    /**
     * @BeforeMethods("setUp")
     * @Revs(200)
     * @Iterations(5)
     */
    public function benchOneOutlinedObjectInALargeWorld(): void
    {
        $this->list->clear();
        $this->system->render($this->world);
    }
}
